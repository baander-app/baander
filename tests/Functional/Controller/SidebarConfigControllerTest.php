<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

/**
 * Functional tests for sidebar configuration management.
 *
 * Covers SidebarConfigController:
 *   GET    /api/user/sidebar-config/{mediaType}   get (defaults if no custom config)
 *   PUT    /api/user/sidebar-config/{mediaType}   update
 *   DELETE /api/user/sidebar-config/{mediaType}   reset to defaults (returns 200, not 204)
 *
 * Valid media types: music, movies, tv, podcasts, concerts, ebooks.
 */
final class SidebarConfigControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET /{mediaType}
    // ---------------------------------------------------------------

    public function testGetRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/user/sidebar-config/music');

        $this->assertJsonResponse($response, 401);
    }

    public function testGetReturnsDefaultConfigForNewUser(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/user/sidebar-config/music', $user),
            200,
            'data',
        );

        $this->assertSame('music', $data['data']['mediaType']);
        $this->assertIsArray($data['data']['sections']);
        $this->assertNotEmpty($data['data']['sections']);
    }

    public function testGetWithInvalidMediaTypeReturns422(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/user/sidebar-config/bogus', $user);

        $this->assertJsonResponse($response, 422);
    }

    public function testGetAcceptsEveryValidMediaType(): void
    {
        $user = $this->createTestUser();

        foreach (['music', 'movies', 'tv', 'podcasts', 'concerts', 'ebooks'] as $mediaType) {
            $response = $this->authenticatedRequest('GET', '/api/user/sidebar-config/' . $mediaType, $user);
            $this->assertSame(200, $response->getStatusCode(), "Media type '{$mediaType}' should be accepted.");
        }
    }

    // ---------------------------------------------------------------
    // PUT /{mediaType}
    // ---------------------------------------------------------------

    public function testUpdateRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('PUT', '/api/user/sidebar-config/music', [
            'sections' => [],
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testUpdateWithInvalidMediaTypeReturns422(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('PUT', '/api/user/sidebar-config/bogus', $user, [
            'sections' => [],
        ]);

        $this->assertJsonResponse($response, 422);
    }

    public function testUpdatePersistsCustomConfig(): void
    {
        $user = $this->createTestUser();
        $sections = [
            [
                'id' => 'music-quick-jump',
                'label' => 'Quick Jump',
                'type' => 'navigation',
                'items' => [
                    ['id' => 'music-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home'],
                ],
            ],
        ];

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PUT', '/api/user/sidebar-config/music', $user, ['sections' => $sections]),
            200,
            'data',
        );

        $this->assertSame('music', $data['data']['mediaType']);
        $this->assertNotEmpty($data['data']['sections']);
    }

    // ---------------------------------------------------------------
    // DELETE /{mediaType}
    // ---------------------------------------------------------------

    public function testDeleteRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/user/sidebar-config/music');

        $this->assertJsonResponse($response, 401);
    }

    public function testDeleteResetsToDefaultsAndReturns200(): void
    {
        // DELETE returns 200 with fresh defaults (not 204 as the OpenAPI doc states).
        $user = $this->createTestUser();

        // Set a custom config first.
        $this->authenticatedRequest('PUT', '/api/user/sidebar-config/music', $user, [
            'sections' => [
                [
                    'id' => 'music-quick-jump',
                    'label' => 'Quick Jump',
                    'type' => 'navigation',
                    'items' => [
                        ['id' => 'music-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home'],
                    ],
                ],
            ],
        ]);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('DELETE', '/api/user/sidebar-config/music', $user),
            200,
            'data',
        );

        $this->assertSame('music', $data['data']['mediaType']);
        $this->assertNotEmpty($data['data']['sections'], 'Reset must return default sections.');
    }

    public function testDeleteWithInvalidMediaTypeReturns422(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('DELETE', '/api/user/sidebar-config/bogus', $user);

        $this->assertJsonResponse($response, 422);
    }
}
