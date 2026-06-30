<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Functional\TestCase;

/**
 * Functional tests for user favorites (Favorites bounded context).
 *
 * Covers FavoritesController:
 *   GET    /api/favorites/              list (with pagination + type filter)
 *   POST   /api/favorites/              add (201)
 *   DELETE /api/favorites/{publicId}    remove
 */
final class FavoritesControllerTest extends TestCase
{
    // ---------------------------------------------------------------
    // GET / (index)
    // ---------------------------------------------------------------

    public function testIndexRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('GET', '/api/favorites/');

        $this->assertJsonResponse($response, 401);
    }

    public function testIndexReturnsEmptyForNewUser(): void
    {
        $user = $this->createTestUser();

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/', $user),
            200,
        );

        $this->assertSame([], $data['data']);
        $this->assertSame(0, $data['meta']['total']);
    }

    public function testIndexReturnsAddedFavorites(): void
    {
        $user = $this->createTestUser();
        $this->addFavorite($user, 'song', 'song-abc123');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/', $user),
            200,
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame(1, $data['meta']['total']);
    }

    public function testIndexFiltersByEntityType(): void
    {
        $user = $this->createTestUser();
        $this->addFavorite($user, 'song', 'song-1');
        $this->addFavorite($user, 'album', 'album-1');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/?entityType=song', $user),
            200,
        );

        $this->assertCount(1, $data['data']);
        $this->assertSame(1, $data['meta']['total']);
    }

    public function testIndexIsolatesByUser(): void
    {
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $this->addFavorite($user, 'song', 'song-1');
        $this->addFavorite($other, 'song', 'song-2');

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/', $user),
            200,
        );

        $this->assertCount(1, $data['data']);
    }

    // ---------------------------------------------------------------
    // POST / (add)
    // ---------------------------------------------------------------

    public function testAddRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('POST', '/api/favorites/', [
            'entityType' => 'song',
            'entityPublicId' => 'song-1',
        ]);

        $this->assertJsonResponse($response, 401);
    }

    public function testAddReturns201(): void
    {
        $user = $this->createTestUser();

        $response = $this->addFavorite($user, 'song', 'song-create');
        $this->assertSame(201, $response->getStatusCode());

        // created() returns flat data (no 'data' wrapper)
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('song', $data['entityType']);
    }

    public function testAddWithInvalidEntityTypeReturns500(): void
    {
        // BUG PIN: AddFavoriteRequest has NotBlank but no Choice constraint on
        // entityType. An invalid type passes DTO validation, then the handler's
        // FavoriteType::from() throws ValueError → 500 instead of 422.
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => 'movie',
            'entityPublicId' => 'movie-1',
        ]);

        $this->assertSame(500, $response->getStatusCode(), 'Invalid entityType produces 500 (missing Choice constraint).');
    }

    public function testAddWithBlankEntityPublicIdFailsValidation(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => 'song',
            'entityPublicId' => '',
        ]);

        $this->assertJsonResponse($response, 422);
    }

    // ---------------------------------------------------------------
    // DELETE /{publicId} (remove)
    // ---------------------------------------------------------------

    public function testRemoveRequiresAuthentication(): void
    {
        $response = $this->anonymousRequest('DELETE', '/api/favorites/someid');

        $this->assertJsonResponse($response, 401);
    }

    public function testRemoveDeletesFavorite(): void
    {
        $user = $this->createTestUser();
        $response = $this->addFavorite($user, 'song', 'song-del');
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $publicId = $data['publicId'];

        $response = $this->authenticatedRequest('DELETE', '/api/favorites/' . $publicId, $user);
        $this->assertSame(200, $response->getStatusCode());

        // Gone from the list.
        $listData = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/favorites/', $user),
            200,
        );
        $this->assertSame(0, $listData['meta']['total']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function addFavorite($user, string $entityType, string $entityPublicId)
    {
        return $this->authenticatedRequest('POST', '/api/favorites/', $user, [
            'entityType' => $entityType,
            'entityPublicId' => $entityPublicId,
        ]);
    }
}
