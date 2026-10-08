<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Functional\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MetadataAdminControllerTest extends TestCase
{
    // --- Sync Status ---

    public function testSyncStatusReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/sync-status', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');

        $this->assertArrayHasKey('lastSyncAt', $data['data']);
        $this->assertArrayHasKey('totalTracks', $data['data']);
        $this->assertArrayHasKey('syncedTracks', $data['data']);
        $this->assertArrayHasKey('pendingTracks', $data['data']);
        $this->assertArrayHasKey('failedTracks', $data['data']);
        $this->assertArrayHasKey('sources', $data['data']);
        $this->assertIsInt($data['data']['totalTracks']);
        $this->assertIsInt($data['data']['syncedTracks']);
        $this->assertIsArray($data['data']['sources']);
    }

    public function testSyncStatusReturns200ForSuperAdmin(): void
    {
        $user = $this->createSuperAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/sync-status', $user);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testSyncStatusReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/sync-status', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Trigger Sync ---

    public function testTriggerSyncReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $user, []);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('jobsDispatched', $data['data']);
        $this->assertIsInt($data['data']['jobsDispatched']);
    }

    public function testTriggerSyncWithGenreSource(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $user, [
            'source' => 'genres',
        ]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertArrayHasKey('jobsDispatched', $data['data']);
    }

    public function testSyncAllQueuesOneLibrarySyncPerLibraryAndReportsTheirCount(): void
    {
        $this->createLibraryWithSongs(1);
        $this->createLibraryWithSongs(1);
        $libraries = $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM libraries ORDER BY id');

        // The page's "Trigger Sync" button sends no source.
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $this->createAdminUser(), ['source' => null]);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertSame(count($libraries), $data['data']['jobsDispatched']);
        $queued = $this->queued(SyncLibraryMessage::class);
        $this->assertCount(count($libraries), $queued);
        $queuedIds = array_map(static fn (SyncLibraryMessage $message): string => $message->libraryId->toString(), $queued);
        sort($queuedIds);
        $this->assertSame($libraries, $queuedIds);
        $this->assertSame([], $this->queued(SyncAlbumMessage::class), 'The library syncs queue the album syncs when they run.');
    }

    public function testGenreSyncQueuesOnlyAlbumAndSongSyncsOnBothPaths(): void
    {
        $this->createLibraryWithSongs(2);

        $data = $this->assertJsonResponse(
            $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $this->createAdminUser(), ['source' => 'genres']),
            200,
            'data',
        );
        $albums = $this->queued(SyncAlbumMessage::class);
        $songs = $this->queued(SyncSongMessage::class);
        $this->assertNotSame([], $albums);
        $this->assertGreaterThanOrEqual(2, count($songs));
        $this->assertSame(count($albums) + count($songs), $data['data']['jobsDispatched']);
        $this->assertSame([], $this->queued(SyncLibraryMessage::class));
        $this->swooleTasks()->reset();

        $tester = new CommandTester((new Application(static::$kernel))->find('app:metadata:sync'));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--source' => 'genres']), $tester->getDisplay());

        $this->assertStringContainsString(sprintf('Queued %d metadata sync job(s)', $data['data']['jobsDispatched']), $tester->getDisplay());
        $this->assertEquals($albums, $this->queued(SyncAlbumMessage::class));
        $this->assertEquals($songs, $this->queued(SyncSongMessage::class));
        $this->assertSame([], $this->queued(SyncLibraryMessage::class));
        $this->assertSame(1, preg_match('/Job ID: (\S+)/', $tester->getDisplay(), $match));
        $this->assertSame(
            ['name' => 'SyncMetadataCommand', 'status' => 'finished'],
            $this->entityManager->getConnection()->fetchAssociative('SELECT name, status FROM job_monitors WHERE job_id = ?', [$match[1]]),
        );
    }

    public function testAnUnknownSourceIsRejectedOnBothPaths(): void
    {
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $this->createAdminUser(), ['source' => 'spotify']);
        $this->assertSame(422, $response->getStatusCode());

        $tester = new CommandTester((new Application(static::$kernel))->find('app:metadata:sync'));
        $this->assertSame(Command::INVALID, $tester->execute(['--source' => 'spotify']));
        $this->assertSame([], $this->swooleTasks()->getSent());
    }

    public function testStatusAndProvidersCommandsPrintTheEndpointsData(): void
    {
        $admin = $this->createAdminUser();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO job_monitors (id, job_id, name, status, created_at, updated_at) VALUES (?, ?, 'SyncAlbumMessage', 'failed', ?, ?)",
            [Uuid::v7()->toString(), (new PublicId())->toString(), '2099-01-02 03:04:05', '2099-01-02 03:04:05'],
        );
        $application = new Application(static::$kernel);

        foreach (['/api/admin/metadata/sync-status' => 'app:metadata:status', '/api/admin/metadata/providers' => 'app:metadata:providers'] as $path => $command) {
            $endpoint = $this->assertJsonResponse($this->authenticatedRequest('GET', $path, $admin), 200, 'data')['data'];
            $tester = new CommandTester($application->find($command));
            $this->assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
            $this->assertSame($endpoint, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR), $command);

            if ($command === 'app:metadata:status') {
                $this->assertSame((new \DateTimeImmutable('2099-01-02 03:04:05'))->format(\DateTimeInterface::ATOM), $endpoint['lastSyncAt']);
                $this->assertGreaterThanOrEqual(1, $endpoint['failedTracks']);
                $this->assertContains('SyncAlbumMessage', array_column($endpoint['sources'], 'name'));
            }
        }
    }

    public function testTriggerSyncReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('POST', '/api/admin/metadata/trigger-sync', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Providers ---

    public function testProvidersReturns200ForAdmin(): void
    {
        $user = $this->createAdminUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/providers', $user);

        $data = $this->assertJsonResponse($response, 200, 'data');
        $this->assertIsArray($data['data']);
        $this->assertNotEmpty($data['data']);

        $provider = $data['data'][0];
        $this->assertArrayHasKey('name', $provider);
        $this->assertArrayHasKey('enabled', $provider);
        $this->assertArrayHasKey('configured', $provider);
    }

    public function testProvidersReturns403ForUser(): void
    {
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/admin/metadata/providers', $user);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function swooleTasks(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.swoole_task');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function queued(string $class): array
    {
        $messages = [];
        foreach ($this->swooleTasks()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof $class) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function createLibraryWithSongs(int $count): void
    {
        $conn = $this->entityManager->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $libraryId = Uuid::v7();
        $albumId = Uuid::v7();
        $slug = 'metadata-admin-' . bin2hex(random_bytes(4));
        $conn->executeStatement(
            'INSERT INTO libraries (id, slug, name, path, type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$libraryId->toString(), $slug, 'Metadata admin', '/music/' . $slug, 'music', 0, $now, $now],
        );
        $conn->executeStatement(
            'INSERT INTO albums (id, public_id, library_id, title, type, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$albumId->toString(), (new PublicId())->toString(), $libraryId->toString(), 'Metadata admin album', 'studio', '{}', $now, $now],
        );
        for ($i = 0; $i < $count; ++$i) {
            $conn->executeStatement(
                'INSERT INTO songs (id, public_id, album_id, title, path, size, mime_type, length, locked_fields, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Uuid::v7()->toString(), (new PublicId())->toString(), $albumId->toString(), 'Track ' . $i, '/music/' . $slug . '/' . $i . '.flac', 1000, 'audio/flac', 180.0, '{}', $now, $now],
            );
        }
        $this->entityManager->clear();
    }
}
