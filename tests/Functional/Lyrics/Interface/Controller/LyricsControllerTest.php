<?php

declare(strict_types=1);

namespace App\Tests\Functional\Lyrics\Interface\Controller;

use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;

final class LyricsControllerTest extends TestCase
{
    private LyricsRepositoryInterface $lyricsRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lyricsRepository = static::getContainer()->get(LyricsRepositoryInterface::class);
    }

    public function testGetLyricsReturnsEmptyWhenNoLyricsExist(): void
    {
        $user = $this->createTestUser();
        [, , $songPublicId] = $this->createSongFixture();

        $response = $this->authenticatedRequest(
            'GET',
            "/api/songs/{$songPublicId}/lyrics",
            $user,
        );

        $data = $this->assertJsonResponse($response, 200);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame([], $data['data']);
    }

    public function testGetLyricsReturnsLyricsWhenExist(): void
    {
        $user = $this->createTestUser();
        [$songId, , $songPublicId] = $this->createSongFixture();

        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: 'Test lyrics line 1',
            source: 'lrclib',
            syncedLyrics: '[00:10.00] Test lyrics line 1',
            lrclibId: 12345,
        );
        $this->lyricsRepository->save($lyrics);

        $response = $this->authenticatedRequest(
            'GET',
            "/api/songs/{$songPublicId}/lyrics",
            $user,
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Test lyrics line 1', $data['data']['plainLyrics']);
        $this->assertSame('[00:10.00] Test lyrics line 1', $data['data']['syncedLyrics']);
        $this->assertSame('lrclib', $data['data']['source']);
        $this->assertFalse($data['data']['isInstrumental']);
    }

    public function testGetLyricsReturns404ForInvalidPublicId(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'GET',
            '/api/songs/nonexistent-public-id/lyrics',
            $user,
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFetchLyricsReturns404ForInvalidPublicId(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/songs/nonexistent-public-id/lyrics/fetch',
            $user,
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFetchLyricsReturnsExistingLyricsWithoutReFetch(): void
    {
        $user = $this->createTestUser();
        [$songId, , $songPublicId] = $this->createSongFixture();

        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: 'Existing lyrics',
            source: 'embedded',
        );
        $this->lyricsRepository->save($lyrics);

        $response = $this->authenticatedRequest(
            'POST',
            "/api/songs/{$songPublicId}/lyrics/fetch",
            $user,
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        // Should return existing lyrics without calling LRCLIB
        $this->assertSame('Existing lyrics', $data['data']['plainLyrics']);
        $this->assertSame('embedded', $data['data']['source']);
    }

    public function testSearchLyricsWithValidQuery(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'GET',
            '/api/lyrics/search?q=Still+Alive+Portal',
            $user,
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testSearchLyricsReturnsErrorWithoutQuery(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'GET',
            '/api/lyrics/search',
            $user,
        );

        // MapQueryString returns 404 when required query params are missing
        $this->assertContains($response->getStatusCode(), [400, 404, 422]);
    }

    public function testApplyLyricsReturnsErrorForInvalidPublicId(): void
    {
        $user = $this->createTestUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/lyrics/search/99999/apply',
            $user,
            ['songPublicId' => 'nonexistent-id'],
        );

        // Invalid publicId format returns 400, valid but nonexistent returns 404
        $this->assertContains($response->getStatusCode(), [400, 404]);
    }

    /**
     * Creates a minimal album + song via raw SQL to satisfy FK constraints.
     *
     * @return array{0: Uuid, 1: Uuid, 2: string} [songId, albumId, songPublicId]
     */
    private function createSongFixture(): array
    {
        $libraryId = Uuid::v7();
        $albumId = Uuid::v7();
        $songId = Uuid::v7();
        $songPublicId = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 21);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $libraryId->toString(),
                'test-lib-' . bin2hex(random_bytes(4)),
                'Test Library',
                '/tmp/test-' . bin2hex(random_bytes(4)),
                'music',
                0,
                $now,
                $now,
            ],
        );

        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $albumId->toString(),
                'test-album-' . bin2hex(random_bytes(6)),
                $libraryId->toString(),
                'Test Album',
                'studio',
                '{}',
                $now,
                $now,
            ],
        );

        $conn->executeStatement(
            'INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $songId->toString(),
                $songPublicId,
                $albumId->toString(),
                'Test Song',
                '/tmp/test-song.mp3',
                1000,
                'audio/mpeg',
                233.0,
                '{}',
                $now,
                $now,
            ],
        );

        // Clear EM cache so it picks up the raw-inserted entities
        $this->entityManager->clear();

        return [$songId, $albumId, $songPublicId];
    }
}
