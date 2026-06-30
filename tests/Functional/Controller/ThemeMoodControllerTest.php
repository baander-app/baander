<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * Functional tests for theme-mood management.
 *
 * Covers ThemeMoodController:
 *   GET  /api/user/theme-mood/   get current mood (defaults to "dark")
 *   PUT  /api/user/theme-mood/   update mood (validated against dark|warm|cool|balanced)
 */
final class ThemeMoodControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /
    // ---------------------------------------------------------------

    public function testGetRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/theme-mood/');

        $this->assertJsonResponse($response, 401);
    }

    public function testGetReturnsDefaultDarkMoodForNewUser(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/theme-mood/', $user),
            200,
            'data',
        );

        $this->assertSame('dark', $data['data']['mood']);
    }

    // ---------------------------------------------------------------
    // PUT /
    // ---------------------------------------------------------------

    public function testUpdateRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PUT', '/api/user/theme-mood/', ['mood' => 'warm']);

        $this->assertJsonResponse($response, 401);
    }

    public function testUpdateChangesMoodAndPersists(): void
    {
        $user = $this->createTestUser();

        $update = $this->authenticatedRequest('PUT', '/api/user/theme-mood/', $user, ['mood' => 'warm']);
        $updateData = $this->assertJsonResponse($update, 200, 'data');
        $this->assertSame('warm', $updateData['data']['mood']);

        // Persisted across requests.
        $getData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/theme-mood/', $user),
            200,
            'data',
        );
        $this->assertSame('warm', $getData['data']['mood']);
    }

    public function testUpdateAcceptsEveryValidMood(): void
    {
        $user = $this->createTestUser();

        foreach (['dark', 'warm', 'cool', 'balanced'] as $mood) {
            $response = $this->authenticatedRequest('PUT', '/api/user/theme-mood/', $user, ['mood' => $mood]);
            $this->assertSame(200, $response->getStatusCode(), "Mood '{$mood}' should be accepted.");
        }
    }

    public function testUpdateWithInvalidMoodFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/theme-mood/', $user, ['mood' => 'purple']);

        $this->assertJsonResponse($response, 422);
    }

    public function testUpdateWithBlankMoodFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/theme-mood/', $user, ['mood' => '']);

        $this->assertJsonResponse($response, 422);
    }
}
