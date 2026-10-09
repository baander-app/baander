<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class LyricsAdminControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        // The queued marks live in Redis, outside the test's database transaction.
        $this->forgetQueuedFetches();

        parent::tearDown();
    }

    // --- Coverage ---

    public function testCoverageReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/coverage', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('totalTracks', $data['data']);
        $this->assertArrayHasKey('tracksWithLyrics', $data['data']);
        $this->assertArrayHasKey('tracksWithoutLyrics', $data['data']);
        $this->assertArrayHasKey('coveragePercentage', $data['data']);
        $this->assertArrayHasKey('bySource', $data['data']);
        $this->assertIsInt($data['data']['totalTracks']);
        $this->assertIsInt($data['data']['tracksWithLyrics']);
        $this->assertIsNumeric($data['data']['coveragePercentage']);
        $this->assertIsArray($data['data']['bySource']);
    }

    public function testCoverageCommandJsonMatchesTheEndpoint(): void
    {
        $songs = $this->createSongs(2);
        static::getContainer()->get(LyricsRepositoryInterface::class)->save(Lyrics::create($songs[0], 'Stored lyrics', 'embedded'));

        $endpoint = $this->assertJsonResponse(
            $this->authenticatedRequest('GET', '/api/admin/lyrics/coverage', $this->createAdminUser()),
            200,
            'data',
        )['data'];

        $tester = new CommandTester((new Application(static::$kernel))->find('app:lyrics:coverage'));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        $this->assertSame($endpoint, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertGreaterThanOrEqual(1, $endpoint['bySource']['embedded'] ?? 0);
    }

    public function testCoverageReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/coverage', $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCoverageReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/coverage', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Bulk Fetch ---

    public function testBulkFetchReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $user, [
            'limit' => 10,
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('jobsEnqueued', $data['data']);
        $this->assertIsInt($data['data']['jobsEnqueued']);
    }

    public function testBulkFetchWithoutABodyQueuesEverySongWithoutLyrics(): void
    {
        $songs = $this->createSongs(3);
        static::getContainer()->get(LyricsRepositoryInterface::class)->save(Lyrics::create($songs[1], 'Stored lyrics', 'embedded'));
        $missing = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM songs s WHERE NOT EXISTS (SELECT 1 FROM lyrics l WHERE l.song_id = s.id)',
        );

        // The admin page's button posts no request body.
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $this->createSuperAdminUser());

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame($missing, $data['data']['jobsEnqueued']);
        $queued = $this->queuedFetches();
        $this->assertCount($missing, $queued);
        $this->assertContains($songs[0]->toString(), $queued);
        $this->assertNotContains($songs[1]->toString(), $queued);
        $this->assertContains($songs[2]->toString(), $queued);
    }

    public function testTheWebAndTheConsoleQueueTheSameSongsForTheSameLimit(): void
    {
        $this->createSongs(12);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $this->createSuperAdminUser(), ['limit' => 10]),
            200,
            'data',
        );
        $this->assertSame(10, $data['data']['jobsEnqueued']);
        $web = $this->queuedFetches();
        $this->assertCount(10, $web);
        $this->forgetQueuedFetches();
        $this->asyncTransport()->reset();

        $tester = new CommandTester((new Application(static::$kernel))->find('app:lyrics:fetch'));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--limit' => '10']), $tester->getDisplay());

        $this->assertSame($web, $this->queuedFetches());
        $this->assertSame([0, 500, 1000], array_slice(array_map(
            static fn (Envelope $envelope): ?int => $envelope->last(DelayStamp::class)?->getDelay(),
            $this->asyncTransport()->getSent(),
        ), 0, 3));
        $this->assertSame(1, preg_match('/Job ID: (\S+)/', $tester->getDisplay(), $match));
        $this->assertSame(
            ['name' => 'BulkFetchLyricsCommand', 'status' => 'finished'],
            $this->entityManager->getConnection()->fetchAssociative('SELECT name, status FROM job_monitors WHERE job_id = ?', [$match[1]]),
        );
    }

    public function testARunThatOverlapsAnEarlierOneSkipsTheSongsItQueued(): void
    {
        $songs = array_map(static fn (Uuid $id): string => $id->toString(), $this->createSongs(3));

        $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $this->createSuperAdminUser(), ['limit' => 2]),
            200,
            'data',
        );
        $first = $this->queuedFetches();
        $this->assertCount(2, $first);

        // The fetches of the first run have not run yet when the console run starts.
        $tester = new CommandTester((new Application(static::$kernel))->find('app:lyrics:fetch'));
        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $all = $this->queuedFetches();
        $this->assertSame(array_values(array_unique($all)), $all, 'No song is queued twice.');
        $this->assertSame(1, preg_match('/Queued (\d+) lyrics fetch/', $tester->getDisplay(), $queued), $tester->getDisplay());
        $this->assertSame(count($all) - 2, (int) $queued[1]);
        foreach ($songs as $songId) {
            $this->assertContains($songId, $all);
        }
    }

    public function testBulkFetchRejectsALimitBelowOne(): void
    {
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $this->createSuperAdminUser(), ['limit' => 0]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->queuedFetches());
    }

    public function testBulkFetchReturns403ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testBulkFetchReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/lyrics/bulk-fetch', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Sync Status ---

    public function testSyncStatusReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('lastSyncAt', $data['data']);
        $this->assertArrayHasKey('recentJobs', $data['data']);
        $this->assertArrayHasKey('failedJobs', $data['data']);
        $this->assertArrayHasKey('completedJobs', $data['data']);
        $this->assertIsInt($data['data']['recentJobs']);
        $this->assertIsInt($data['data']['failedJobs']);
        $this->assertIsInt($data['data']['completedJobs']);
    }

    public function testSyncStatusCountsTheLyricsJobsTheMonitorRecords(): void
    {
        $admin = $this->createAdminUser();
        $before = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $admin), 200, 'data')['data'];
        $this->insertJob('FetchLyricsCommand', 'finished', '2099-01-02 03:04:05');
        $this->insertJob('FetchLyricsCommand', 'failed', (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));
        $this->insertJob('BulkFetchLyricsCommand', 'finished', (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'));
        $this->insertJob('SyncAlbumMessage', 'failed', (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));

        $after = $this->assertJsonResponse($this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $admin), 200, 'data')['data'];

        $this->assertSame($before['recentJobs'] + 2, $after['recentJobs']);
        $this->assertSame($before['completedJobs'] + 2, $after['completedJobs']);
        $this->assertSame($before['failedJobs'] + 1, $after['failedJobs']);
        $this->assertSame((new \DateTimeImmutable('2099-01-02 03:04:05'))->format(\DateTimeInterface::ATOM), $after['lastSyncAt']);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:lyrics:status'));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        $this->assertSame($after, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testSyncStatusReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSyncStatusReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/lyrics/sync-status', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** Removes the queued marks of the fetches this test queued. */
    private function forgetQueuedFetches(): void
    {
        $marks = static::getContainer()->get(QueuedLyricsFetchesInterface::class);
        foreach ($this->queuedFetches() as $songId) {
            $marks->clearQueued(Uuid::fromString($songId));
        }
    }

    /** @return list<string> the song IDs of the queued lyrics fetches, in queue order */
    private function queuedFetches(): array
    {
        $songIds = [];
        foreach ($this->asyncTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchLyricsCommand) {
                $songIds[] = $message->getSongId()->toString();
            }
        }

        return $songIds;
    }

    private function insertJob(string $name, string $status, string $createdAt): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO job_monitors (id, job_id, name, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::v7()->toString(), (new PublicId())->toString(), $name, $status, $createdAt, $createdAt],
        );
    }

    /** @return list<Uuid> */
    private function createSongs(int $count): array
    {
        $conn = $this->entityManager->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $libraryId = Uuid::v7();
        $albumId = Uuid::v7();
        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$libraryId->toString(), 'lyrics-admin-' . bin2hex(random_bytes(4)), 'Lyrics admin', '/music/lyrics-admin', 'music', 0, $now, $now],
        );
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$albumId->toString(), (new PublicId())->toString(), $libraryId->toString(), 'Lyrics admin album', 'studio', '{}', $now, $now],
        );

        $songs = [];
        for ($i = 0; $i < $count; ++$i) {
            $songId = Uuid::v7();
            $songs[] = $songId;
            $conn->executeStatement(
                'INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$songId->toString(), (new PublicId())->toString(), $albumId->toString(), 'Track ' . $i, '/music/lyrics-admin/' . $i . '.flac', 1000, 'audio/flac', 180.0, '{}', $now, $now],
            );
        }
        $this->entityManager->clear();

        return $songs;
    }
}
