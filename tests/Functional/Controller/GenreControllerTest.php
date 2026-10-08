<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Auth\Domain\Model\User;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Library\Infrastructure\Doctrine\Entity\UserLibraryAccessEntity;
use App\Shared\Domain\Model\PublicId;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for genre management (Catalog bounded context).
 *
 * Covers GenreController:
 *   GET    /api/genres/         index (accessible library genres, root-only or flat)
 *   POST   /api/genres/         store (ROLE_ADMIN only)
 *   GET    /api/genres/{slug}   show (accessible library genres)
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
        $this->grantGenreLibraryAccess($user, ['rock', 'jazz']);

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
        $this->assertSame(['jazz', 'rock'], array_column($rootData['data'], 'slug'));
        $this->assertSame(['jazz', 'rock'], array_column($flatData['data'], 'slug'));
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
        $this->assertSame('Electronic', $data['data']['name']);
        $this->assertSame('electronic', $data['data']['slug']);
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
        $root = $this->assertJsonResponse($this->createGenre($admin, 'Rock', 'rock'), 201, 'data')['data'];
        $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/genres/', $admin, [
                'name' => 'Hard Rock',
                'slug' => 'hard-rock',
                'parentId' => $root['uuid'],
            ]),
            201,
        );
        $this->grantGenreLibraryAccess($user, ['rock', 'hard-rock']);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/genres/rock', $user),
            200,
            'data',
        );

        $this->assertSame('Rock', $data['data']['name']);
        $this->assertSame('rock', $data['data']['slug']);
        $this->assertArrayHasKey('children', $data['data']);
        $this->assertCount(1, $data['data']['children']);
        $this->assertSame('hard-rock', $data['data']['children'][0]['slug']);
        $this->assertSame($root['uuid'], $data['data']['children'][0]['parentId']);
    }

    public function testShowDeniesGenreWithoutAccessibleMedia(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createTestUser();
        $this->createGenre($admin, 'Orphan', 'orphan');

        $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/genres/orphan', $user),
            404,
        );
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
            $this->authenticatedRequest('GET', '/api/genres/rock', $admin),
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

    private function createGenre(User $admin, string $name, string $slug): Response
    {
        return $this->authenticatedRequest('POST', '/api/genres/', $admin, [
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    /** @param list<string> $slugs */
    private function grantGenreLibraryAccess(User $user, array $slugs): void
    {
        $entity = $this->entityManager->find(UserEntity::class, $user->getId());
        self::assertInstanceOf(UserEntity::class, $entity);

        $suffix = bin2hex(random_bytes(8));
        $library = new LibraryEntity('Genre fixture', 'genre-' . $suffix, '/tmp/genre-' . $suffix, 'music', 'local');
        $album = new AlbumEntity(new PublicId(), $library, 'Genre fixture album', 'album');
        $this->entityManager->persist($library);
        $this->entityManager->persist($album);
        $this->entityManager->persist(new UserLibraryAccessEntity($entity->getId(), $library, new \DateTimeImmutable()));

        foreach ($slugs as $slug) {
            $genre = $this->entityManager->getRepository(GenreEntity::class)->findOneBy(['slug' => $slug]);
            self::assertInstanceOf(GenreEntity::class, $genre);
            $this->entityManager->persist(new GenreAlbumEntity($genre, $album));
        }

        $this->entityManager->flush();
    }
}
