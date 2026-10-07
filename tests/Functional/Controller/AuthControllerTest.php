<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Doctrine\Entity\EmailVerificationTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
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

        $data = $this->assertJsonResponse($response, 201, 'data');
        $this->assertSame($email, $data['data']['email']);
        $this->assertSame('New User', $data['data']['name']);
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

    public function testRegisterWithDuplicateEmailFails(): void
    {
        $email = 'dup-' . bin2hex(random_bytes(4)) . '@baander.app';
        $this->createTestUser($email);

        $response = $this->anonymousRequest('POST', '/api/auth/register', [
            'email' => $email,
            'name' => 'Another User',
            'password' => 'securepassword123',
        ]);

        $this->assertJsonResponse($response, 400);
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
        $this->assertArrayHasKey('uuid', $data['data']);
        $this->assertArrayHasKey('publicId', $data['data']);
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
        $userEntity = $this->entityManager->find(UserEntity::class, $user->getId());

        $token = new EmailVerificationTokenEntity(
            $userEntity,
            'functional-test-token',
            new \DateTimeImmutable('+1 hour'),
        );
        $this->entityManager->persist($token);
        $this->entityManager->flush();

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
        $userEntity = $this->entityManager->find(UserEntity::class, $user->getId());

        $token = new EmailVerificationTokenEntity(
            $userEntity,
            'expired-functional-token',
            new \DateTimeImmutable('-1 hour'),
        );
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $response = $this->anonymousRequest('POST', '/api/auth/email/verify', [
            'token' => 'expired-functional-token',
        ]);

        $this->assertJsonResponse($response, 400);
    }

    // ---------------------------------------------------------------
    // PUT /api/auth/me/email
    // ---------------------------------------------------------------

    public function testChangingTheEmailClearsItsVerification(): void
    {
        $user = $this->createAdminUser();
        $this->assertTrue($user->isEmailVerified(), 'Operator-created users start verified.');
        $newEmail = 'changed-' . bin2hex(random_bytes(4)) . '@baander.app';

        $response = $this->authenticatedRequest('PUT', '/api/auth/me/email', $user, ['email' => $newEmail]);

        $this->assertJsonResponse($response, 200);
        $this->entityManager->clear();
        $stored = $this->userRepository->findByUuid($user->getId());
        $this->assertNotNull($stored);
        $this->assertSame($newEmail, $stored->getEmail());
        $this->assertFalse($stored->isEmailVerified(), 'A new address is unverified until its owner confirms it.');
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
}
