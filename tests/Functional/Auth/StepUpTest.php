<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Changing one's own email or password, starting TOTP setup, turning TOTP off, and adding or
 * removing a passkey each need the current password, plus a current TOTP code when TOTP is on.
 * Every mismatch gets one answer, and the attempts are limited per account.
 */
final class StepUpTest extends RateLimitTestCase
{
    use EnrolsTotp;

    private const string PASSWORD = 'password123';

    public function testWithTotpOnTheRightPasswordWithoutACodeGetsTheAnswerOfAWrongPassword(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);
        $address = $user->getEmail();

        $noCode = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $this->uniqueEmail(), 'currentPassword' => self::PASSWORD]);
        $wrongPassword = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $this->uniqueEmail(), 'currentPassword' => 'not-the-password', 'totpCode' => self::currentTotpCode()]);

        $this->assertStepUpFailed($noCode);
        self::assertSame($noCode->getContent(), $wrongPassword->getContent());
        $this->entityManager->clear();
        self::assertSame($address, $this->userRepository->findByUuid($user->getId())?->getEmail());
    }

    public function testTheRightPasswordAndCodeRecordAPendingEmailChange(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);
        $address = $this->uniqueEmail();

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $address, 'currentPassword' => self::PASSWORD, 'totpCode' => self::currentTotpCode()]);

        $this->assertJsonResponse($response, 202, 'data');
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        self::assertNotNull($stored);
        self::assertSame($user->getEmail(), $stored->getEmail());
        self::assertSame($address, $stored->getPendingEmail());
    }

    public function testACodeConfirmsOneChangeOnly(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);
        $code = self::currentTotpCode();

        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $this->uniqueEmail(), 'currentPassword' => self::PASSWORD, 'totpCode' => $code]), 202);

        $this->assertStepUpFailed($this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $this->uniqueEmail(), 'currentPassword' => self::PASSWORD, 'totpCode' => $code]));
    }

    public function testAnEmailChangeWithoutThePasswordIsInvalid(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $this->uniqueEmail()]);

        $error = $this->assertJsonResponse($response, 422, 'error');
        self::assertArrayHasKey('currentPassword', $error['error']['details'] ?? []);
    }

    public function testTotpSetupNeedsThePassword(): void
    {
        $user = $this->createTestUser(verified: true);

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user), 422);
        $this->assertStepUpFailed($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user, ['currentPassword' => 'not-the-password']));
        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user, ['currentPassword' => self::PASSWORD]), 200, 'data');
        self::assertNotSame('', $data['data']['secret']);
    }

    public function testPasskeyOptionsNeedThePasswordAndTheCode(): void
    {
        $user = $this->createTestUser(verified: true);
        $this->enrolTotp($user);

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/passkey/options', $user), 422);
        $this->assertStepUpFailed($this->authenticatedRequest('POST', '/api/auth/passkey/options', $user, ['currentPassword' => self::PASSWORD]));
        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/passkey/options', $user, ['currentPassword' => self::PASSWORD, 'totpCode' => self::currentTotpCode()]), 200, 'data');
        self::assertIsString($data['data']['challengeKey']);
    }

    public function testAChallengeIssuedToOneUserIsRefusedAtAnotherUsersRegistration(): void
    {
        $owner = $this->createTestUser(verified: true);
        $other = $this->createTestUser(verified: true);
        $options = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/passkey/options', $owner, ['currentPassword' => self::PASSWORD]), 200, 'data');

        $response = $this->authenticatedRequest('POST', '/api/auth/passkey/register', $other, [
            'challengeKey' => $options['data']['challengeKey'],
            'response' => ['clientDataJSON' => 'e30', 'attestationObject' => 'oA'],
            'name' => 'Borrowed challenge',
        ]);

        $this->assertJsonResponse($response, 400, 'error');
    }

    public function testAnUnverifiedAddressCannotStartTotpSetupOrPasskeyRegistration(): void
    {
        $user = $this->createTestUser();

        foreach (['/api/auth/totp/setup', '/api/auth/passkey/options'] as $path) {
            $error = $this->assertJsonResponse($this->authenticatedRequest('POST', $path, $user, ['currentPassword' => self::PASSWORD]), 422, 'error');
            self::assertSame('email_unverified', $error['error']['details']['reason'] ?? null, $path);
            self::assertSame('Verify your email address first.', $error['error']['message'], $path);
        }
    }

    public function testTheLimitCountsAttemptsPerAccountAcrossAddresses(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId()->toString();
        $this->trackLimiter('auth_step_up_user', $userId);
        // The test environment sets every limit to 10000; leave one attempt.
        self::assertTrue($this->limiter('auth_step_up_user')->create($userId)->consume(9999)->isAccepted());

        $failed = $this->requestFrom('198.51.100.40', 'PUT', '/api/auth/me/email', $userId, ['email' => $this->uniqueEmail(), 'currentPassword' => 'not-the-password']);
        $limited = $this->requestFrom('198.51.100.41', 'PUT', '/api/auth/me/email', $userId, ['email' => $this->uniqueEmail(), 'currentPassword' => self::PASSWORD]);

        $this->assertStepUpFailed($failed);
        $this->assertTooManyRequests($limited);
        $this->entityManager->clear();
        self::assertSame($user->getEmail(), $this->userRepository->findByUuid($user->getId())?->getEmail());
    }

    public function testTurningTotpOffWithTheCodeAloneIsRefusedAndTotpStaysOn(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);

        $codeAlone = $this->authenticatedRequest('POST', '/api/auth/totp/disable', $user, ['totpCode' => self::currentTotpCode()]);
        $wrongPassword = $this->authenticatedRequest('POST', '/api/auth/totp/disable', $user, ['currentPassword' => 'not-the-password', 'totpCode' => self::currentTotpCode()]);

        $this->assertStepUpFailed($codeAlone);
        self::assertSame($codeAlone->getContent(), $wrongPassword->getContent());
        self::assertTrue($this->storedUser($user)->hasTotp());
    }

    public function testThePasswordAndTheCodeTurnTotpOff(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);

        $response = $this->authenticatedRequest('POST', '/api/auth/totp/disable', $user, ['currentPassword' => self::PASSWORD, 'totpCode' => self::currentTotpCode()]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        self::assertSame('Two-factor authentication turned off.', $data['data']['message']);
        self::assertFalse($this->storedUser($user)->hasTotp());
    }

    public function testTurningTotpOffWhileItIsOffIsRefusedWithoutSpendingAnAttempt(): void
    {
        $user = $this->createTestUser();
        $userId = $user->getId()->toString();
        $this->leaveOneStepUpAttempt($userId);

        $refused = $this->requestFrom('198.51.100.150', 'POST', '/api/auth/totp/disable', $userId, ['currentPassword' => self::PASSWORD, 'totpCode' => '123456']);
        $lastAttempt = $this->requestFrom('198.51.100.150', 'PUT', '/api/auth/me/email', $userId, ['email' => $this->uniqueEmail(), 'currentPassword' => 'not-the-password']);

        $error = $this->assertJsonResponse($refused, 400, 'error');
        self::assertSame('Two-factor authentication is not on for this account.', $error['error']['message']);
        $this->assertStepUpFailed($lastAttempt);
    }

    public function testWithTotpOnAPasswordChangeWithoutTheCodeGetsTheAnswerOfAWrongPassword(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);

        $noCode = $this->authenticatedRequest('PUT', '/api/auth/me/password', $user, ['currentPassword' => self::PASSWORD, 'newPassword' => 'changed-password-1']);
        $wrongPassword = $this->authenticatedRequest('PUT', '/api/auth/me/password', $user, ['currentPassword' => 'not-the-password', 'newPassword' => 'changed-password-2', 'totpCode' => self::currentTotpCode()]);

        $this->assertStepUpFailed($noCode);
        self::assertSame($noCode->getContent(), $wrongPassword->getContent());
        self::assertTrue(password_verify(self::PASSWORD, $this->storedUser($user)->getPassword()), 'The password stays the same.');
    }

    public function testThePasswordAndTheCodeChangeThePassword(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/password', $user, ['currentPassword' => self::PASSWORD, 'newPassword' => 'changed-password-3', 'totpCode' => self::currentTotpCode()]);

        $this->assertJsonResponse($response, 200, 'data');
        self::assertTrue(password_verify('changed-password-3', $this->storedUser($user)->getPassword()));
    }

    public function testRemovingAPasskeyNeedsThePasswordAndTheCode(): void
    {
        $user = $this->createTestUser(verified: true);
        $this->enrolTotp($user);
        $passkey = $this->storePasskey($user);
        $path = '/api/auth/passkey/' . $passkey->getPublicId()->toString();

        $this->assertJsonResponse($this->authenticatedRequest('DELETE', $path, $user), 422);
        $this->assertStepUpFailed($this->authenticatedRequest('DELETE', $path, $user, ['currentPassword' => self::PASSWORD]));
        self::assertNotNull($this->passkeys()->ofId($passkey->getId()), 'The passkey stays.');

        $data = $this->assertJsonResponse($this->authenticatedRequest('DELETE', $path, $user, ['currentPassword' => self::PASSWORD, 'totpCode' => self::currentTotpCode()]), 200, 'data');

        self::assertSame('Passkey deleted successfully.', $data['data']['message']);
        $this->entityManager->clear();
        self::assertNull($this->passkeys()->ofId($passkey->getId()));
        self::assertSame(['user.passkey_deleted'], $this->recordedEvents($user, 'user.passkey_deleted'));
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function stepUpRoutes(): iterable
    {
        yield 'turning TOTP off' => ['POST', '/api/auth/totp/disable', []];
        yield 'changing the password' => ['PUT', '/api/auth/me/password', ['newPassword' => 'changed-password-4']];
        yield 'removing a passkey' => ['DELETE', '/api/auth/passkey/{passkey}', []];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('stepUpRoutes')]
    public function testTheLimitCoversEveryCredentialChange(string $method, string $path, array $change): void
    {
        $user = $this->createTestUser(verified: true);
        $this->enrolTotp($user);
        $path = str_replace('{passkey}', $this->storePasskey($user)->getPublicId()->toString(), $path);
        $userId = $user->getId()->toString();
        $this->leaveOneStepUpAttempt($userId);

        $failed = $this->requestFrom('198.51.100.151', $method, $path, $userId, [...$change, 'currentPassword' => 'not-the-password', 'totpCode' => self::currentTotpCode()]);
        $limited = $this->requestFrom('198.51.100.152', $method, $path, $userId, [...$change, 'currentPassword' => self::PASSWORD, 'totpCode' => self::currentTotpCode()]);

        $this->assertStepUpFailed($failed);
        $this->assertTooManyRequests($limited);
        self::assertTrue($this->storedUser($user)->hasTotp());
    }

    private function leaveOneStepUpAttempt(string $userId): void
    {
        $this->trackLimiter('auth_step_up_user', $userId);
        // The test environment sets every limit to 10000.
        self::assertTrue($this->limiter('auth_step_up_user')->create($userId)->consume(9999)->isAccepted());
    }

    private function storedUser(User $user): User
    {
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    private function storePasskey(User $user): Passkey
    {
        $passkey = Passkey::create(Uuid::generate(), 'Laptop', 'credential-' . bin2hex(random_bytes(8)), ['userHandle' => $user->getPublicId()->toString()], 0);
        $this->passkeys()->save($passkey, $user->getId());

        return $passkey;
    }

    private function passkeys(): PasskeyRepositoryInterface
    {
        $passkeys = static::getContainer()->get(PasskeyRepositoryInterface::class);
        self::assertInstanceOf(PasskeyRepositoryInterface::class, $passkeys);

        return $passkeys;
    }

    /** @return list<string> the names of the user's recorded events called $name */
    private function recordedEvents(User $user, string $name): array
    {
        /** @var list<string> */
        return $this->entityManager->getConnection()->fetchFirstColumn(
            "SELECT event_name FROM domain_event_outbox WHERE event_name = :name AND payload->>'user_id' = :user",
            ['name' => $name, 'user' => $user->getId()->toString()],
        );
    }

    private function assertStepUpFailed(Response $response): void
    {
        $error = $this->assertJsonResponse($response, 422, 'error');
        self::assertSame('step_up_failed', $error['error']['details']['reason'] ?? null, (string) $response->getContent());
        self::assertSame('The password or code is incorrect.', $error['error']['message']);
    }

    private function uniqueEmail(): string
    {
        return 'step-up-' . bin2hex(random_bytes(4)) . '@baander.app';
    }
}
