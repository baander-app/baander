<?php

declare(strict_types=1);

namespace App\Tests\Functional\Lyrics\Interface\Console;

use App\Lyrics\Application\DTO\LrclibResult;
use App\Lyrics\Application\DTO\LrclibSearchResult;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Infrastructure\Doctrine\Entity\LyricsEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\Lyrics\FakeLrclibClient;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:song:lyrics:fetch, app:lyrics:search and app:lyrics:apply reach the use cases LyricsController
 * reaches, store the same lyrics and report the same outcomes. The songs belong to libraries nobody
 * was granted; the commands find them through the unrestricted scope.
 */
final class SongLyricsCliParityTest extends TestCase
{
    private FakeLrclibClient $lrclib;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lrclib = new FakeLrclibClient();
        static::getContainer()->set(LrclibClientInterface::class, $this->lrclib);
    }

    public function testFetchStoresTheSameLyricsAndPrintsTheApiData(): void
    {
        $admin = $this->createAdminUser();
        [$songId, $publicId] = $this->song();
        $this->lrclib->signatureAnswer = $this->stillAlive();

        $api = $this->assertJsonResponse($this->authenticatedRequest('POST', "/api/songs/{$publicId}/lyrics/fetch", $admin), 200, 'data');
        $storedByApi = $this->stored($songId);
        $this->forget($songId);

        $fetch = $this->command('app:song:lyrics:fetch');
        self::assertSame(Command::SUCCESS, $fetch->execute(['song' => $publicId, '--json' => true]), $fetch->getDisplay());

        self::assertSame($api['data'], $this->json($fetch));
        self::assertNotNull($storedByApi);
        self::assertSame($storedByApi, $this->stored($songId));
    }

    public function testAnOutageAnswers503AndExits1WithTheSameMessage(): void
    {
        $admin = $this->createAdminUser();
        [$songId, $publicId] = $this->song();
        $this->lrclib->unavailable();

        $error = $this->assertJsonResponse($this->authenticatedRequest('POST', "/api/songs/{$publicId}/lyrics/fetch", $admin), 503);
        self::assertSame(LyricsProviderUnavailableException::MESSAGE, $error['error']['message']);

        $fetch = $this->command('app:song:lyrics:fetch');
        self::assertSame(Command::FAILURE, $fetch->execute(['song' => $publicId]));
        self::assertStringContainsString(LyricsProviderUnavailableException::MESSAGE, $this->normalized($fetch->getDisplay()));

        $search = $this->command('app:lyrics:search');
        self::assertSame(Command::FAILURE, $search->execute(['query' => ['Still Alive']]));
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/lyrics/search?q=Still+Alive', $admin), 503);

        self::assertNull($this->stored($songId));
    }

    public function testSearchPrintsTheApiData(): void
    {
        $admin = $this->createAdminUser();
        $this->lrclib->searchAnswer = [new LrclibSearchResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', null)];

        $api = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/lyrics/search?q=Still+Alive', $admin), 200, 'data');

        $search = $this->command('app:lyrics:search');
        self::assertSame(Command::SUCCESS, $search->execute(['query' => ['Still', 'Alive'], '--json' => true]));
        self::assertSame($api['data'], $this->json($search));

        $blank = $this->command('app:lyrics:search');
        self::assertSame(Command::INVALID, $blank->execute([]));
        $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/lyrics/search', $admin), 422);
    }

    public function testApplyStoresTheResultOnceAndBothPathsReportTheConflictAfter(): void
    {
        $admin = $this->createAdminUser();
        [$songId, $publicId] = $this->song();
        $this->lrclib->byIdAnswer = $this->stillAlive();

        $api = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/lyrics/search/912345/apply', $admin, ['songPublicId' => $publicId]),
            200,
            'data',
        );
        $storedByApi = $this->stored($songId);
        $this->forget($songId);

        $apply = $this->command('app:lyrics:apply');
        self::assertSame(Command::SUCCESS, $apply->execute(['result-id' => '912345', 'song' => $publicId, '--json' => true]), $apply->getDisplay());
        self::assertSame($api['data'], $this->json($apply));
        self::assertNotNull($storedByApi);
        self::assertSame($storedByApi, $this->stored($songId));

        // Another result for the song that now has lyrics: both paths refuse it and keep them.
        $this->lrclib->byIdAnswer = new LrclibResult(912346, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'Other lyrics', null);
        $conflict = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/lyrics/search/912346/apply', $admin, ['songPublicId' => $publicId]),
            409,
        );
        $again = $this->command('app:lyrics:apply');
        self::assertSame(Command::FAILURE, $again->execute(['result-id' => '912346', 'song' => $publicId]));
        self::assertStringContainsString($conflict['error']['message'], $this->normalized($again->getDisplay()));
        self::assertSame($storedByApi, $this->stored($songId));
    }

    private function stillAlive(): LrclibResult
    {
        return new LrclibResult(912345, 'Still Alive', 'GLaDOS', 'Portal', 175.0, false, 'This was a triumph', '[00:01.00] This was a triumph');
    }

    /** @return array<string, mixed>|null the stored lyrics, without the row's own ID and times */
    private function stored(Uuid $songId): ?array
    {
        $this->entityManager->clear();
        $lyrics = $this->entityManager->getRepository(LyricsEntity::class)->findOneBy(['songId' => $songId]);
        if ($lyrics === null) {
            return null;
        }

        return [
            'plainLyrics' => $lyrics->getPlainLyrics(),
            'syncedLyrics' => $lyrics->getSyncedLyrics(),
            'source' => $lyrics->getSource(),
            'lrclibId' => $lyrics->getLrclibId(),
        ];
    }

    /** Deletes the song's lyrics, so the other path starts from the same state. */
    private function forget(Uuid $songId): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM lyrics WHERE song_id = ?', [$songId->toString()]);
        $this->entityManager->clear();
    }

    /**
     * A song with an artist, in a library nobody was granted.
     *
     * @return array{0: Uuid, 1: string} [songId, songPublicId]
     */
    private function song(): array
    {
        $libraryId = Uuid::v7();
        $albumId = Uuid::v7();
        $songId = Uuid::v7();
        $artistId = Uuid::v7();
        $publicId = (new PublicId())->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$libraryId->toString(), 'lyrics-cli-' . bin2hex(random_bytes(4)), 'Lyrics CLI', '/tmp/lyrics-cli-' . bin2hex(random_bytes(4)), 'music', 0, $now, $now],
        );
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$albumId->toString(), (new PublicId())->toString(), $libraryId->toString(), 'Portal', 'studio', '{}', $now, $now],
        );
        $conn->executeStatement(
            'INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$songId->toString(), $publicId, $albumId->toString(), 'Still Alive', '/tmp/lyrics-cli.mp3', 1000, 'audio/mpeg', 175.0, '{}', $now, $now],
        );
        $conn->executeStatement(
            'INSERT INTO artists (id, public_id, name, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$artistId->toString(), (new PublicId())->toString(), 'GLaDOS', '{}', $now, $now],
        );
        $conn->executeStatement(
            "INSERT INTO artist_song (id, artist_id, song_id, role) VALUES (?, ?, ?, 'primary')",
            [Uuid::v7()->toString(), $artistId->toString(), $songId->toString()],
        );
        $this->entityManager->clear();

        return [$songId, $publicId];
    }

    /** @return array<array-key, mixed> */
    private function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** The output on one line, as SymfonyStyle wraps long error messages. */
    private function normalized(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new Application($this->client->getKernel()))->find($name));
    }
}
