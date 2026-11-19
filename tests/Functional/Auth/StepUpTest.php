<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\HttpFoundation\Response;

/**
 * Changing one's own email, starting TOTP setup and starting passkey registration each need the
 * current password, plus a current TOTP code when TOTP is on. Every mismatch gets one answer,
 * and the attempts are limited per account.
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

    public function testTheRightPasswordAndCodeChangeTheEmail(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);
        $address = $this->uniqueEmail();

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $address, 'currentPassword' => self::PASSWORD, 'totpCode' => self::currentTotpCode()]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        self::assertSame($address, $data['data']['email']);
        self::assertTrue($data['data']['totpEnabled']);
    }

    public function testACodeConfirmsOneChangeOnly(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);
        $code = self::currentTotpCode();

        $this->assertJsonResponse($this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $this->uniqueEmail(), 'currentPassword' => self::PASSWORD, 'totpCode' => $code]), 200);

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
        $user = $this->createTestUser();

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user), 422);
        $this->assertStepUpFailed($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user, ['currentPassword' => 'not-the-password']));
        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/totp/setup', $user, ['currentPassword' => self::PASSWORD]), 200, 'data');
        self::assertNotSame('', $data['data']['secret']);
    }

    public function testPasskeyOptionsNeedThePasswordAndTheCode(): void
    {
        $user = $this->createTestUser();
        $this->enrolTotp($user);

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/passkey/options', $user), 422);
        $this->assertStepUpFailed($this->authenticatedRequest('POST', '/api/auth/passkey/options', $user, ['currentPassword' => self::PASSWORD]));
        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/passkey/options', $user, ['currentPassword' => self::PASSWORD, 'totpCode' => self::currentTotpCode()]), 200, 'data');
        self::assertIsString($data['data']['challengeKey']);
    }

    public function testAChallengeIssuedToOneUserIsRefusedAtAnotherUsersRegistration(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $options = $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/passkey/options', $owner, ['currentPassword' => self::PASSWORD]), 200, 'data');

        $response = $this->authenticatedRequest('POST', '/api/auth/passkey/register', $other, [
            'challengeKey' => $options['data']['challengeKey'],
            'response' => ['clientDataJSON' => 'e30', 'attestationObject' => 'oA'],
            'name' => 'Borrowed challenge',
        ]);

        $this->assertJsonResponse($response, 400, 'error');
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
