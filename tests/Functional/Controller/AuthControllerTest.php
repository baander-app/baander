<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Domain\Model\User;
use App\Shared\Domain\Model\Email;
use App\Tests\Functional\TestCase;

final class AuthControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // POST /api/auth/register
    // ---------------------------------------------------------------

    public function testRegisterCreatesUser(): void
    {
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

        $response = $this->anonymousRequest('POST', '/api/auth/email/verify', [
            'token' => 'functional-test-token',
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Email verified.', $data['data']['message']);

        $verifiedUser = $this->userRepository->findByUuid($user->getId());
        $this->assertTrue($verifiedUser->isEmailVerified());
    }

    public function testVerifyEmailWithExpiredTokenFails(): void
    {
        $user = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email($user->getEmail()), 'expired-functional-token', new \DateTimeImmutable('-1 hour'));

        $response = $this->anonymousRequest('POST', '/api/auth/email/verify', [
            'token' => 'expired-functional-token',
        ]);

        $data = $this->assertJsonResponse($response, 400, 'error');
        $this->assertSame('This verification link is invalid or has expired.', $data['error']['message']);
    }

    public function testVerifyEmailRejectsATokenForAnAddressTheAccountNoLongerHas(): void
    {
        $user = $this->createTestUser();
        $this->verificationTokens()->issue($user->getId(), new Email('previous-' . $user->getEmail()), 'stale-address-token', new \DateTimeImmutable('+1 hour'));

        $this->assertJsonResponse($this->anonymousRequest('POST', '/api/auth/email/verify', ['token' => 'stale-address-token']), 400);

        $this->entityManager->clear();
        $this->assertFalse($this->userRepository->findByUuid($user->getId())?->isEmailVerified());
    }

    // ---------------------------------------------------------------
    // PUT /api/auth/me/email
    // ---------------------------------------------------------------

    public function testChangingTheEmailClearsItsVerification(): void
    {
        $user = $this->createAdminUser();
        $this->assertTrue($user->isEmailVerified(), 'Operator-created users start verified.');
        $newEmail = 'changed-' . bin2hex(random_bytes(4)) . '@baander.app';

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $newEmail, 'currentPassword' => 'password']);

        $this->assertJsonResponse($response, 200);
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        $this->assertNotNull($stored);
        $this->assertSame($newEmail, $stored->getEmail());
        $this->assertFalse($stored->isEmailVerified(), 'A new address is unverified until its owner confirms it.');
    }

    public function testChangingToAnAddressInUseIsAConflict(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $other->getEmail(), 'currentPassword' => 'password123']);

        $data = $this->assertJsonResponse($response, 409, 'error');
        $this->assertSame('This email address is already in use.', $data['error']['message']);
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
        $this->assertSame('Current password is incorrect.', $data['error']['message']);
    }

    private function verificationTokens(): EmailVerificationTokenRepositoryInterface
    {
        $repository = static::getContainer()->get(EmailVerificationTokenRepositoryInterface::class);
        $this->assertInstanceOf(EmailVerificationTokenRepositoryInterface::class, $repository);

        return $repository;
    }
}
