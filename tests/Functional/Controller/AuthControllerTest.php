<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Settings\UserManagementSettingDefinitions;
use App\Auth\Domain\Model\User;
use App\Shared\Application\Port\SystemSettingStoreInterface;
use App\Shared\Domain\Model\Email;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AuthControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // POST /api/auth/register
    // ---------------------------------------------------------------

    public function testRegistrationIsClosedUntilAnOperatorOpensIt(): void
    {
        $email = 'closed-' . bin2hex(random_bytes(4)) . '@baander.app';

        $response = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => $email,
            'name' => 'Turned Away',
            'password' => 'securepassword123',
        ]);

        $data = $this->assertJsonResponse($response, 403, 'error');
        $this->assertSame('registration_closed', $data['error']['details']['reason'] ?? null);
        $this->assertSame('Registration is closed. Ask the administrator for an account.', $data['error']['message']);
        $this->assertNull($this->userRepository->findByEmail(new Email($email)), 'A closed registration creates no account.');
        $this->assertEmailCount(0);
    }

    public function testTheSettingsCommandOpensRegistration(): void
    {
        $tester = new CommandTester(new Application(static::$kernel)->find('app:settings:set'));
        $tester->execute(['key' => 'auth.registration_open', 'value' => 'true']);
        $tester->assertCommandIsSuccessful();
        $email = 'opened-' . bin2hex(random_bytes(4)) . '@baander.app';

        $response = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => $email,
            'name' => 'Let In',
            'password' => 'securepassword123',
        ]);

        $this->assertJsonResponse($response, 202, 'data');
        $this->assertNotNull($this->userRepository->findByEmail(new Email($email)));
    }

    public function testTheRegistrationStateIsPublic(): void
    {
        $closed = $this->anonymousRequest('GET', '/api/auth/registration');
        $this->assertSame(['open' => false], $this->assertJsonResponse($closed, 200, 'data')['data']);

        $this->openRegistration();

        $open = $this->anonymousRequest('GET', '/api/auth/registration');
        $this->assertSame(['open' => true], $this->assertJsonResponse($open, 200, 'data')['data']);
    }

    public function testRegisterCreatesUser(): void
    {
        $this->openRegistration();
        $email = 'new-' . bin2hex(random_bytes(4)) . '@baander.app';

        $response = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => $email,
            'name' => 'New User',
            'password' => 'securepassword123',
        ]);

        $data = $this->assertJsonResponse($response, 202, 'data');
        $this->assertSame(['message' => 'Registration received. Check your email for the next step.'], $data['data']);
    }

    public function testRegisterPersistsUser(): void
    {
        $this->openRegistration();
        $email = 'persist-' . bin2hex(random_bytes(4)) . '@baander.app';

        $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => $email,
            'name' => 'Persisted User',
            'password' => 'securepassword123',
        ]);

        // Verify the user was actually persisted (controller-to-handler-to-repo wiring)
        $user = $this->userRepository->findByEmail(new \App\Shared\Domain\Model\Email($email));
        $this->assertNotNull($user, 'User should be persisted after registration.');
        $this->assertSame($email, $user->getEmail());
        $this->assertSame('Persisted User', $user->getName());
    }

    /** A taken address gets the answer a new one gets; only its owner learns of the attempt. */
    public function testRegisteringATakenAddressAnswersLikeANewOneAndEmailsTheOwner(): void
    {
        $this->openRegistration();
        $email = 'dup-' . bin2hex(random_bytes(4)) . '@baander.app';
        $owner = $this->createTestUser($email, name: 'Original Owner');
        $fresh = 'fresh-' . bin2hex(random_bytes(4)) . '@baander.app';

        $new = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => $fresh,
            'name' => 'Another User',
            'password' => 'securepassword123',
        ]);
        $taken = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => strtoupper($email),
            'name' => 'Another User',
            'password' => 'securepassword123',
        ]);

        $this->assertSame(202, $taken->getStatusCode(), (string) $taken->getContent());
        $this->assertSame($new->getStatusCode(), $taken->getStatusCode());
        $this->assertSame($new->getContent(), $taken->getContent());

        $toOwner = array_values(array_filter(
            $this->getMailerMessages(),
            static fn (object $message): bool => $message instanceof \Symfony\Component\Mime\Email
                && array_map(static fn (\Symfony\Component\Mime\Address $a): string => $a->getAddress(), $message->getTo()) === [$email],
        ));
        $this->assertCount(1, $toOwner, 'The owner gets one notice.');
        $notice = $toOwner[0];
        $this->assertInstanceOf(\Symfony\Component\Mime\Email::class, $notice);
        $this->assertStringContainsString('Someone tried to sign up', (string) $notice->getSubject());
        $this->assertStringContainsString('Hi Original Owner,', (string) $notice->getTextBody());
        $this->assertStringContainsString('/forgot-password', (string) $notice->getTextBody());

        $this->entityManager->clear();
        $stored = $this->userRepository->findByEmail(new Email($email));
        $this->assertNotNull($stored);
        $this->assertTrue($stored->getId()->equals($owner->getId()));
        $this->assertSame('Original Owner', $stored->getName(), 'The existing account is left as it was.');
        $this->assertSame($owner->getPassword(), $stored->getPassword());
    }

    public function testRegisterWithInvalidDataFails(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => 'not-an-email',
            'name' => '',
            'password' => 'short',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    public function testRegisterValidationErrorResponseContainsDetails(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => 'not-an-email',
            'name' => '',
            'password' => 'ab',
        ]);

        $data = $this->assertJsonResponse($response, 422);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('message', $data['error']);
        $this->assertSame('Validation failed.', $data['error']['message']);
        $this->assertArrayHasKey('details', $data['error']);
        $this->assertIsArray($data['error']['details']);
    }

    // ---------------------------------------------------------------
    // GET /api/auth/me
    // ---------------------------------------------------------------

    public function testMeReturnsUserProfileForAuthenticatedUser(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/auth/me', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('email', $data['data']);
        $this->assertArrayNotHasKey('uuid', $data['data']);
        $this->assertArrayNotHasKey('id', $data['data']);
        $this->assertSame($user->getPublicId()->toString(), $data['data']['publicId']);
        $this->assertStringNotContainsString($user->getId()->toString(), $response->getContent());
        $this->assertArrayHasKey('name', $data['data']);
    }

    public function testMeReturns404ForUnauthenticatedRequest(): void
    {
        $response = $this->anonymousRequest('GET', '/api/auth/me');

        // The controller returns notFound() when no user is authenticated,
        // because /api/auth/ has PUBLIC_ACCESS at the firewall level.
        $this->assertJsonResponse($response, 404);
    }

    // ---------------------------------------------------------------
    // POST /api/auth/password/reset-request
    // ---------------------------------------------------------------

    public function testPasswordResetRequestWithInvalidEmailFails(): void
    {
        $response = $this->anonymousRequest('POST', '/api/auth/password/reset-request', [
            'email' => 'not-an-email',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // POST /api/auth/email/verify
    // ---------------------------------------------------------------

    public function testVerifyEmailWithValidTokenMarksUserVerified(): void
    {
        $user = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email($user->getEmail()), 'functional-test-token', new \DateTimeImmutable('+1 hour'));

        $response = $this->authenticatedRequest('POST', '/api/auth/email/verify', $user, [
            'token' => 'functional-test-token',
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame(['message' => 'Email verified.', 'emailChanged' => false], $data['data']);

        $verifiedUser = $this->userRepository->findByUuid($user->getId());
        $this->assertTrue($verifiedUser->isEmailVerified());
    }

    /** Holding the link is not enough: signed out, nothing is verified and the link stays usable. */
    public function testAnAnonymousRedemptionIsRefusedAndLeavesTheLinkForTheAccount(): void
    {
        $user = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email($user->getEmail()), 'signed-out-token', new \DateTimeImmutable('+1 hour'));

        $this->assertJsonResponse($this->anonymousRequest('POST', '/api/auth/email/verify', ['token' => 'signed-out-token']), 401);

        $this->assertSame(
            ['verified' => false, 'tokens' => 1],
            $this->verificationState($user),
            'The address stays unverified and the token stays stored.',
        );
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/email/verify', $user, ['token' => 'signed-out-token']), 200);
        $this->assertSame(['verified' => true, 'tokens' => 0], $this->verificationState($user));
    }

    public function testAnotherAccountCannotRedeemTheLinkAndTheAccountStillCan(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser(verified: true);
        $this->verificationTokens()->issue($user->getId(), new Email($user->getEmail()), 'other-account-token', new \DateTimeImmutable('+1 hour'));

        $response = $this->authenticatedRequest('POST', '/api/auth/email/verify', $other, ['token' => 'other-account-token']);

        $data = $this->assertJsonResponse($response, 403, 'error');
        $this->assertSame('This verification link was sent for another account. Sign out, then sign in to that account to use it.', $data['error']['message']);
        $this->assertSame(['reason' => 'verification_link_other_account'], $data['error']['details']);
        $this->assertSame(['verified' => false, 'tokens' => 1], $this->verificationState($user));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/email/verify', $user, ['token' => 'other-account-token']), 200);
        $this->assertSame(['verified' => true, 'tokens' => 0], $this->verificationState($user));
    }

    public function testADelegatedTokenCannotRedeemALink(): void
    {
        $user = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email($user->getEmail()), 'delegated-token', new \DateTimeImmutable('+1 hour'));

        $response = $this->delegatedRequest('POST', '/api/auth/email/verify', $user, ['profile', 'email'], ['token' => 'delegated-token']);

        $this->assertJsonResponse($response, 403);
        $this->assertSame(['verified' => false, 'tokens' => 1], $this->verificationState($user));
    }

    public function testVerifyEmailWithExpiredTokenFails(): void
    {
        $user = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email($user->getEmail()), 'expired-functional-token', new \DateTimeImmutable('-1 hour'));

        $response = $this->authenticatedRequest('POST', '/api/auth/email/verify', $user, [
            'token' => 'expired-functional-token',
        ]);

        $data = $this->assertJsonResponse($response, 400, 'error');
        $this->assertSame('This verification link is invalid or has expired.', $data['error']['message']);
    }

    /** Another account's expired link is as unusable as an unknown one, and says nothing more. */
    public function testUnknownUsedAndAnotherAccountsExpiredTokensGetTheSameAnswer(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email($user->getEmail()), 'used-token', new \DateTimeImmutable('+1 hour'));
        $this->verificationTokens()->issue($other->getId(), new Email($other->getEmail()), 'expired-other-token', new \DateTimeImmutable('-1 hour'));
        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/email/verify', $user, ['token' => 'used-token']), 200);

        $answers = array_map(
            fn (string $token): string => (string) $this->authenticatedRequest('POST', '/api/auth/email/verify', $user, ['token' => $token])->getContent(),
            ['unknown-token', 'used-token', 'expired-other-token'],
        );

        $expected = json_encode(['error' => ['message' => 'This verification link is invalid or has expired.', 'code' => 400]], JSON_THROW_ON_ERROR);
        $this->assertSame([$expected, $expected, $expected], $answers);
    }

    public function testVerifyEmailRejectsATokenForAnAddressTheAccountNoLongerHas(): void
    {
        $user = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email('previous-' . $user->getEmail()), 'stale-address-token', new \DateTimeImmutable('+1 hour'));

        $this->assertJsonResponse($this->authenticatedRequest('POST', '/api/auth/email/verify', $user, ['token' => 'stale-address-token']), 400);

        $this->entityManager->clear();
        $this->assertFalse($this->userRepository->findByUuid($user->getId())?->isEmailVerified());
    }

    // ---------------------------------------------------------------
    // PUT /api/auth/me/email
    // ---------------------------------------------------------------

    public function testChangingTheEmailKeepsTheVerifiedAddressUntilTheNewOneIsConfirmed(): void
    {
        $user = $this->createAdminUser();
        $this->assertTrue($user->isEmailVerified(), 'Operator-created users start verified.');
        $newEmail = 'changed-' . bin2hex(random_bytes(4)) . '@baander.app';

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $newEmail, 'currentPassword' => 'password']);

        $this->assertJsonResponse($response, 202);
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        $this->assertNotNull($stored);
        $this->assertSame($user->getEmail(), $stored->getEmail());
        $this->assertTrue($stored->isEmailVerified());
        $this->assertSame($newEmail, $stored->getPendingEmail());
        $me = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/auth/me', $user), 200, 'data');
        $this->assertSame($newEmail, $me['data']['pendingEmail']);
    }

    public function testChangingToAnAddressInUseAnswersLikeAnyOtherChange(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $other->getEmail(), 'currentPassword' => 'password123']);

        $data = $this->assertJsonResponse($response, 202, 'data');
        $this->assertSame('Check the new address for a link to confirm the change.', $data['data']['message']);
    }

    // ---------------------------------------------------------------
    // PUT /api/auth/me/password
    // ---------------------------------------------------------------

    public function testChangingThePasswordRequiresTheCurrentOne(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/password', $user, [
            'currentPassword' => 'not-the-password',
            'newPassword' => 'another-password-456',
        ]);

        $data = $this->assertJsonResponse($response, 422, 'error');
        $this->assertSame('The password or code is incorrect.', $data['error']['message']);
        $this->assertSame('step_up_failed', $data['error']['details']['reason'] ?? null);
    }

    private function openRegistration(): void
    {
        static::getContainer()->get(SystemSettingStoreInterface::class)->save([UserManagementSettingDefinitions::REGISTRATION_OPEN => true]);
    }

    /** @return array{verified: bool, tokens: int} whether the address is verified, and how many tokens the user holds */
    private function verificationState(User $user): array
    {
        $state = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT email_verified_at IS NOT NULL AS verified, (SELECT count(*) FROM email_verification_tokens WHERE user_id = users.id) AS tokens FROM users WHERE id = ?',
            [$user->getId()->toString()],
        );
        $this->assertIsArray($state);

        return ['verified' => (bool) $state['verified'], 'tokens' => (int) $state['tokens']];
    }

    private function verificationTokens(): EmailVerificationTokenRepositoryInterface
    {
        $repository = static::getContainer()->get(EmailVerificationTokenRepositoryInterface::class);
        $this->assertInstanceOf(EmailVerificationTokenRepositoryInterface::class, $repository);

        return $repository;
    }
}
