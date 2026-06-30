<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Tests\Functional\TestCase;

/**
 * Functional tests for genre management (Catalog bounded context).
 *
 * Covers GenreController:
 *   GET    /api/genres/         index (public, root-only or flat)
 *   POST   /api/genres/         store (ROLE_ADMIN only)
 *   GET    /api/genres/{slug}   show (public)
 *   PATCH  /api/genres/{slug}   update (ROLE_ADMIN only)
 *   DELETE /api/genres/{slug}   destroy (ROLE_ADMIN only)
 */
final class GenreControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET / (index)
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/genres/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsEmptyListForNewDatabase(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/genres/', $user),
            200,
            'data',
        );

        $this->assertIsArray($data['data']);
    }

    public function testIndexWithFlatQueryReturnsAllGenres(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createTestUser();
        $this->createGenre($admin, 'Rock', 'rock');
        $this->createGenre($admin, 'Jazz', 'jazz');

        $rootData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/genres/', $user),
            200,
            'data',
        );

        $flatData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/genres/?flat=true', $user),
            200,
            'data',
        );

        $this->assertGreaterThanOrEqual(count($rootData['data']), count($flatData['data']));
    }

    // ---------------------------------------------------------------
    // POST / (store) — admin only
    // ---------------------------------------------------------------

    public function testStoreRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/genres/', [
            'name' => 'Rock',
            'slug' => 'rock',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testStoreRequiresAdminRole(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/genres/', $user, [
            'name' => 'Rock',
            'slug' => 'rock',
        ]);

        $this->assertJsonResponse($response, 403);
    }

    public function testStoreCreatesGenre(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->createGenre($admin, 'Electronic', 'electronic');
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Electronic', $data['name']);
        $this->assertSame('electronic', $data['slug']);
    }

    public function testStoreWithBlankNameFailsValidation(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('POST', '/api/genres/', $admin, [
            'name' => '',
            'slug' => 'empty',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // GET /{slug} (show)
    // ---------------------------------------------------------------

    public function testShowReturnsGenreWithChildren(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createTestUser();
        $this->createGenre($admin, 'Rock', 'rock');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/genres/rock', $user),
            200,
            'data',
        );

        $this->assertSame('Rock', $data['data']['name']);
        $this->assertSame('rock', $data['data']['slug']);
        $this->assertArrayHasKey('children', $data['data']);
    }

    public function testShowReturns404ForUnknownSlug(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('GET', '/api/genres/nonexistent-genre', $user);

        $this->assertJsonResponse($response, 404);
    }

    // ---------------------------------------------------------------
    // PATCH /{slug} (update) — admin only
    // ---------------------------------------------------------------

    public function testUpdateRequiresAdminRole(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createTestUser();
        $this->createGenre($admin, 'Rock', 'rock');

        $response = $this->authenticatedRequest('PATCH', '/api/genres/rock', $user, [
            'name' => 'Rock Music',
        ]);

        $this->assertJsonResponse($response, 403);
    }

    public function testUpdateChangesName(): void
    {
        $admin = $this->createAdminUser();
        $this->createGenre($admin, 'Rock', 'rock');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('PATCH', '/api/genres/rock', $admin, [
                'name' => 'Rock Music',
            ]),
            200,
            'data',
        );

        $this->assertSame('Rock Music', $data['data']['name']);
    }

    public function testUpdateReturns404ForUnknownSlug(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('PATCH', '/api/genres/nonexistent', $admin, [
            'name' => 'X',
        ]);

        $this->assertJsonResponse($response, 404);
    }

    // ---------------------------------------------------------------
    // DELETE /{slug} (destroy) — admin only
    // ---------------------------------------------------------------

    public function testDestroyRequiresAdminRole(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createTestUser();
        $this->createGenre($admin, 'Rock', 'rock');

        $response = $this->authenticatedRequest('DELETE', '/api/genres/rock', $user);

        $this->assertJsonResponse($response, 403);
    }

    public function testDestroyRemovesGenre(): void
    {
        $admin = $this->createAdminUser();
        $this->createGenre($admin, 'Rock', 'rock');

        $response = $this->authenticatedRequest('DELETE', '/api/genres/rock', $admin);

        $this->assertSame(204, $response->getStatusCode());

        // Gone.
        $this->assertJsonResponse(
            $this->anonymousRequest('GET', '/api/genres/rock'),
            404,
        );
    }

    public function testDestroyReturns404ForUnknownSlug(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->authenticatedRequest('DELETE', '/api/genres/nonexistent', $admin);

        $this->assertJsonResponse($response, 404);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createGenre(User $admin, string $name, string $slug)
    {
        return $this->authenticatedRequest('POST', '/api/genres/', $admin, [
            'name' => $name,
            'slug' => $slug,
        ]);
    }
}
