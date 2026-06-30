<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

final class AuthControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // POST /api/auth/register
    // ---------------------------------------------------------------

    public function testRegisterCreatesUser(): void
    {
        $email = 'new-' . bin2hex(random_bytes(4)) . '@example.com';

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
        $email = 'persist-' . bin2hex(random_bytes(4)) . '@example.com';

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
        $email = 'dup-' . bin2hex(random_bytes(4)) . '@example.com';
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
}
