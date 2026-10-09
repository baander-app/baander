<?php

declare(strict_types=1);

namespace App\Tests\Functional\Lyrics\Interface\Controller;

use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\Lyrics\FakeLrclibClient;
use App\Tests\Functional\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class LyricsControllerTest extends TestCase
{
    private LyricsRepositoryInterface $lyricsRepository;
    private FakeLrclibClient $lrclib;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lrclib = new FakeLrclibClient();
        static::getContainer()->set(LrclibClientInterface::class, $this->lrclib);

        $this->lyricsRepository = static::getContainer()->get(LyricsRepositoryInterface::class);
    }

    public function testGetLyricsReturnsEmptyWhenNoLyricsExist(): void
    {
        $user = $this->createTestUser();
        [, , $songPublicId] = $this->createSongFixture($user->getId());

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
        [$songId, , $songPublicId] = $this->createSongFixture($user->getId());

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

    public function testFetchLyricsReturns404ForAnUnknownSong(): void
    {
        $user = $this->createAdminUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/songs/nonexistent-public-id/lyrics/fetch',
            $user,
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFetchLyricsReturns422ForAMalformedPublicId(): void
    {
        $response = $this->authenticatedRequest('POST', '/api/songs/not-a-public-id/lyrics/fetch', $this->createAdminUser());

        $error = $this->assertJsonResponse($response, 422);
        $this->assertSame('Invalid public ID format.', $error['error']['message']);
    }

    public function testFetchLyricsStoresWhatLrclibReturns(): void
    {
        $admin = $this->createAdminUser();
        [$songId, , $songPublicId] = $this->createSongFixture($admin->getId());
        $this->lrclib->signatureAnswer = new LrclibResult(912345, 'Test Song', 'Lyrics test artist', 'Test Album', 233.0, false, 'Fetched lyrics', null);

        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', "/api/songs/{$songPublicId}/lyrics/fetch", $admin), 200, 'data');

        $this->assertSame('Fetched lyrics', $data['data']['plainLyrics']);
        $this->assertSame(912345, $this->lyricsRepository->findBySongId($songId)?->getLrclibId());
    }

    public function testFetchLyricsWithNothingFoundReturnsEmptyData(): void
    {
        $admin = $this->createAdminUser();
        [$songId, , $songPublicId] = $this->createSongFixture($admin->getId());

        $data = $this->assertJsonResponse($this->authenticatedRequest('POST', "/api/songs/{$songPublicId}/lyrics/fetch", $admin), 200, 'data');

        $this->assertSame([], $data['data']);
        $this->assertSame(['getBySignatureCached', 'getBySignature'], $this->lrclib->calls);
        $this->assertNull($this->lyricsRepository->findBySongId($songId));
    }

    public function testFetchLyricsDuringAnLrclibOutageAnswers503(): void
    {
        $admin = $this->createAdminUser();
        [$songId, , $songPublicId] = $this->createSongFixture($admin->getId());
        $this->lrclib->unavailable();

        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', "/api/songs/{$songPublicId}/lyrics/fetch", $admin), 503);

        $this->assertSame(LyricsProviderUnavailableException::MESSAGE, $error['error']['message']);
        $this->assertNull($this->lyricsRepository->findBySongId($songId));
    }

    /** Deliberate: a fetch never replaces lyrics a song has; apply reports a conflict instead. */
    public function testFetchLyricsReturnsExistingLyricsWithoutReFetch(): void
    {
        $user = $this->createAdminUser();
        [$songId, , $songPublicId] = $this->createSongFixture($user->getId());

        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: 'Existing lyrics',
            source: 'embedded',
        );
        $this->lyricsRepository->save($lyrics);
        $this->lrclib->unavailable();

        $response = $this->authenticatedRequest(
            'POST',
            "/api/songs/{$songPublicId}/lyrics/fetch",
            $user,
        );

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame('Existing lyrics', $data['data']['plainLyrics']);
        $this->assertSame('embedded', $data['data']['source']);
        $this->assertSame([], $this->lrclib->calls);
    }

    public function testAnAutomaticFetchDuringAnLrclibOutageCompletesWithNoLyrics(): void
    {
        $admin = $this->createAdminUser();
        [$songId] = $this->createSongFixture($admin->getId());
        $this->lrclib->unavailable();

        // Handled as a worker handles a queued fetch: no exception means no retry and no failure entry.
        $envelope = static::getContainer()->get(MessageBusInterface::class)
            ->dispatch(new Envelope(new FetchLyricsCommand($songId), [new ReceivedStamp('async')]));

        $this->assertInstanceOf(Envelope::class, $envelope);
        $this->assertSame(['getBySignatureCached', 'getBySignature'], $this->lrclib->calls);
        $this->assertNull($this->lyricsRepository->findBySongId($songId));
    }

    public function testSearchLyricsWithValidQuery(): void
    {
        $user = $this->createTestUser();
        $this->lrclib->searchAnswer = [new LrclibSearchResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', null)];

        $data = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/lyrics/search?q=Still+Alive+Portal', $user), 200, 'data');

        $this->assertSame(912345, $data['data'][0]['id']);
        $this->assertSame('Still Alive', $data['data'][0]['trackName']);
    }

    public function testSearchLyricsWithoutAQueryIsInvalidInput(): void
    {
        $user = $this->createTestUser();

        foreach (['/api/lyrics/search', '/api/lyrics/search?q=', '/api/lyrics/search?q=+'] as $uri) {
            $error = $this->assertJsonResponse($this->authenticatedRequest('GET', $uri, $user), 422);
            $this->assertSame('Search query is required.', $error['error']['message'], $uri);
        }
        $this->assertSame([], $this->lrclib->calls);
    }

    public function testSearchLyricsDuringAnLrclibOutageAnswers503(): void
    {
        $this->lrclib->unavailable();

        $error = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/lyrics/search?q=Still+Alive', $this->createTestUser()), 503);

        $this->assertSame(LyricsProviderUnavailableException::MESSAGE, $error['error']['message']);
    }

    public function testApplyLyricsReturns422ForAMalformedPublicId(): void
    {
        $user = $this->createAdminUser();

        $response = $this->authenticatedRequest(
            'POST',
            '/api/lyrics/search/99999/apply',
            $user,
            ['songPublicId' => 'nonexistent-id'],
        );

        $error = $this->assertJsonResponse($response, 422);
        $this->assertSame('Invalid public ID format.', $error['error']['message']);
    }

    public function testApplyLyricsStoresTheResultForASongWithoutLyrics(): void
    {
        $admin = $this->createAdminUser();
        [$songId, , $songPublicId] = $this->createSongFixture($admin->getId());
        $this->lrclib->byIdAnswer = new LrclibResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', null);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/lyrics/search/912345/apply', $admin, ['songPublicId' => $songPublicId]),
            200,
            'data',
        );

        $this->assertSame('This was a triumph', $data['data']['plainLyrics']);
        $this->assertSame(912345, $this->lyricsRepository->findBySongId($songId)?->getLrclibId());
    }

    public function testApplyLyricsToASongWithLyricsAnswers409AndKeepsThem(): void
    {
        $admin = $this->createAdminUser();
        [$songId, , $songPublicId] = $this->createSongFixture($admin->getId());
        $this->lyricsRepository->save(Lyrics::create($songId, 'Existing lyrics', 'embedded'));
        $this->lrclib->byIdAnswer = new LrclibResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', null);

        $error = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/lyrics/search/912345/apply', $admin, ['songPublicId' => $songPublicId]),
            409,
        );

        $this->assertSame('The song already has lyrics.', $error['error']['message']);
        $this->entityManager->clear();
        $this->assertSame('Existing lyrics', $this->lyricsRepository->findBySongId($songId)?->getLyrics());
    }

    public function testApplyingAResultAnotherSongHasStoresItForThisSongToo(): void
    {
        $admin = $this->createAdminUser();
        [$firstSong, , $firstPublicId] = $this->createSongFixture($admin->getId());
        [$secondSong, , $secondPublicId] = $this->createSongFixture($admin->getId());
        $this->lrclib->byIdAnswer = new LrclibResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', null);
        $apply = fn(string $publicId) => $this->authenticatedRequest('POST', '/api/lyrics/search/912345/apply', $admin, ['songPublicId' => $publicId]);
        $this->assertJsonResponse($apply($firstPublicId), 200, 'data');

        $data = $this->assertJsonResponse($apply($secondPublicId), 200, 'data');

        $this->assertSame('This was a triumph', $data['data']['plainLyrics']);
        $this->entityManager->clear();
        $this->assertSame(912345, $this->lyricsRepository->findBySongId($firstSong)?->getLrclibId());
        $this->assertSame(912345, $this->lyricsRepository->findBySongId($secondSong)?->getLrclibId());
    }

    public function testApplyLyricsDistinguishesAnUnknownResultFromAnOutage(): void
    {
        $admin = $this->createAdminUser();
        [, , $songPublicId] = $this->createSongFixture($admin->getId());
        $apply = fn() => $this->authenticatedRequest('POST', '/api/lyrics/search/404404/apply', $admin, ['songPublicId' => $songPublicId]);

        $error = $this->assertJsonResponse($apply(), 404);
        $this->assertSame('LRCLIB has no lyrics with ID 404404.', $error['error']['message']);

        $this->lrclib->unavailable();
        $this->assertJsonResponse($apply(), 503);
    }

    /**
     * Creates a minimal album, artist and song via raw SQL to satisfy FK constraints.
     *
     * @return array{0: Uuid, 1: Uuid, 2: string} [songId, albumId, songPublicId]
     */
    private function createSongFixture(Uuid $userId): array
    {
        $libraryId = Uuid::v7();
        $albumId = Uuid::v7();
        $songId = Uuid::v7();
        $artistId = Uuid::v7();
        $songPublicId = (new PublicId())->toString();
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
                (new PublicId())->toString(),
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

        // A lookup by signature needs the song's artist.
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$artistId->toString(), (new PublicId())->toString(), 'Lyrics test artist', '{}', $now, $now],
        );
        $conn->executeStatement(
            "INSERT INTO artist_song (id, artist_id, song_id, role) VALUES (?, ?, ?, 'primary')",
            [Uuid::v7()->toString(), $artistId->toString(), $songId->toString()],
        );

        $access = static::getContainer()->get(LibraryAccessPortInterface::class);
        $access->grant($userId, $libraryId);

        // Clear EM cache so it picks up the raw-inserted entities
        $this->entityManager->clear();

        return [$songId, $albumId, $songPublicId];
    }
}
