<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * Functional tests for accent-color management.
 *
 * Covers AccentColorController:
 *   GET /api/user/accent-color/   get current color (defaults to "violet")
 *   PUT /api/user/accent-color/   update color (NotBlank, max 32 chars — no choice list)
 */
final class AccentColorControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /
    // ---------------------------------------------------------------

    public function testGetRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/accent-color/');

        $this->assertJsonResponse($response, 401);
    }

    public function testGetReturnsDefaultVioletForNewUser(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/accent-color/', $user),
            200,
            'data',
        );

        $this->assertSame('violet', $data['data']['color']);
    }

    // ---------------------------------------------------------------
    // PUT /
    // ---------------------------------------------------------------

    public function testUpdateRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PUT', '/api/user/accent-color/', ['color' => 'blue']);

        $this->assertJsonResponse($response, 401);
    }

    public function testUpdateChangesColorAndPersists(): void
    {
        $user = $this->createTestUser();

        $update = $this->authenticatedRequest('PUT', '/api/user/accent-color/', $user, ['color' => 'emerald']);
        $updateData = $this->assertJsonResponse($update, 200, 'data');
        $this->assertSame('emerald', $updateData['data']['color']);

        // Persisted across requests.
        $getData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/accent-color/', $user),
            200,
            'data',
        );
        $this->assertSame('emerald', $getData['data']['color']);
    }

    public function testUpdateAcceptsArbitraryNonBlankString(): void
    {
        // No Choice constraint — any non-blank string up to 32 chars is valid.
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/accent-color/', $user, ['color' => '#3b82f6']);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public function testUpdateWithBlankColorFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/accent-color/', $user, ['color' => '']);

        $this->assertJsonResponse($response, 422);
    }

    public function testUpdateWithColorExceedingMaxLengthFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'PUT',
            '/api/user/accent-color/',
            $user,
            ['color' => str_repeat('a', 33)],
        );

        $this->assertJsonResponse($response, 422);
    }
}
