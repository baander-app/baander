<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveUpdates;

use App\Catalog\Application\CommandHandler\FilesDiscoveredHandler;
use App\Library\Application\Command\ScanLibraryCommand;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Message\FilesDiscovered;
use App\Library\Domain\Event\LibraryDirectoryIndexed;
use App\Library\Infrastructure\Messaging\LibraryMessagePayloadCodec;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Metadata\Infrastructure\Messaging\MetadataMessagePayloadCodec;
use App\Metadata\Infrastructure\Reader\WavReader;
use App\Notification\Application\DTO\SendPushCommand;
use App\Notification\Domain\ValueObject\NotificationCategory;
use App\Notification\Infrastructure\Messaging\NotificationMessagePayloadCodec;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\LiveUpdates\DoctrineLiveChangeProjector;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Redis\RedisClientFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;

require_once dirname(__DIR__, 2) . '/Fixtures/LiveUpdates/capacity-fixture.php';

final class CapacityFixtureTest extends TestCase
{
    private Connection $writer;
    private Connection $observer;
    private string $schema;
    private string $root;
    private string $mediaRoot;
    private \LiveCapacityFixture $fixture;

    protected function setUp(): void
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if (!$url) {
            self::markTestSkipped('Set OUTBOX_TEST_DATABASE_URL to disposable PostgreSQL.');
        }
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        $this->writer = DriverManager::getConnection($params);
        $this->observer = DriverManager::getConnection($params);
        $this->schema = 'live_capacity_' . bin2hex(random_bytes(8));
        $this->writer->executeStatement('CREATE SCHEMA ' . $this->schema);
        foreach ([$this->writer, $this->observer] as $connection) {
            $connection->executeStatement('SET search_path TO ' . $this->schema . ', public');
            $connection->executeStatement("SET TIME ZONE 'UTC'");
        }
        $files = glob(dirname(__DIR__, 3) . '/migrations/Version*.php');
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            require_once $file;
            $class = 'DoctrineMigrations\\' . basename($file, '.php');
            $migration = new $class($this->writer, new NullLogger());
            $migration->up(new Schema());
            $this->writer->transactional(static function (Connection $connection) use ($migration): void {
                foreach ($migration->getSql() as $query) {
                    if (preg_match('/\ACREATE EXTENSION\b/i', ltrim($query->getStatement())) !== 1) {
                        $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                    }
                }
            });
        }
        $this->root = sys_get_temp_dir() . '/' . $this->schema;
        $this->mediaRoot = $this->root . '-media';
        $this->fixture = new \LiveCapacityFixture($this->writer, $this->root, $this->mediaRoot);
    }

    protected function tearDown(): void
    {
        if (!isset($this->writer)) {
            return;
        }
        $this->observer->executeStatement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
        $this->observer->close();
        foreach (isset($this->root) ? [$this->root, $this->mediaRoot] : [] as $root) {
            if (is_dir($root)) {
                foreach (glob($root . '/*') as $directory) {
                    foreach (glob($directory . '/*') as $file) {
                        if (is_dir($file)) {
                            foreach (glob($file . '/*') as $source) {
                                unlink($source);
                            }
                            rmdir($file);
                        } else {
                            unlink($file);
                        }
                    }
                    rmdir($directory);
                }
                rmdir($root);
            }
        }
    }

    public function testProducerCapturesEveryNotificationAndCleanupPreservesOtherRuns(): void
    {
        $owned = $this->fixture->execute(['op' => 'create', 'run' => 'owned']);
        $other = $this->fixture->execute(['op' => 'create', 'run' => 'other']);
        self::assertSame($this->root . '/owned', $owned['directory']);
        self::assertSame($this->mediaRoot . '/owned', $owned['mediaDirectory']);
        self::assertSame($owned['mediaDirectory'], $owned['library']['path']);
        self::assertSame($owned['mediaDirectory'] . '/owned.wav', $owned['song']['path']);
        self::assertSame(0700, fileperms($owned['directory']) & 0777);
        self::assertSame(0600, fileperms($owned['directory'] . '/manifest.json') & 0777);
        self::assertSame(0755, fileperms($owned['mediaDirectory']) & 0777);
        self::assertSame(0644, fileperms($owned['song']['path']) & 0777);
        self::assertCount(32, $owned['users']);
        self::assertCount(32, array_unique(array_column($owned['users'], 'id')));
        self::assertSame([false], array_values(array_unique(array_column($owned['users'], 'admin'))));
        self::assertTrue($owned['observer']['admin']);
        $hash = $this->observer->fetchOne('SELECT password FROM users WHERE id = ?', [$owned['users'][0]['id']]);
        self::assertTrue(password_verify($owned['users'][0]['password'], $hash));
        self::assertStringEndsWith('@baander.app', $owned['users'][0]['email']);
        $wave = file_get_contents($owned['song']['path']);
        self::assertSame('RIFF', substr($wave, 0, 4));
        self::assertSame(60 * 8000 * 2 + 44, strlen($wave));
        self::assertSame(32, (int) $this->observer->fetchOne('SELECT count(*) FROM user_library_access WHERE library_id = ? AND user_id <> ?', [$owned['library']['id'], $owned['observer']['id']]));
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?', [$owned['playlist']['id'], $owned['users'][3]['id']]));
        $projector = new DoctrineLiveChangeProjector($this->observer);
        $this->drain($projector);
        $ordinary = $this->fixture->execute(['op' => 'ordinary', 'run' => 'owned']);
        self::assertSame(32, $ordinary['capturedRows']);
        self::assertSame(array_fill(0, 32, 1), array_column($ordinary['users'], 'count'));
        self::assertGreaterThanOrEqual($ordinary['commitStartEpochMs'], $ordinary['commitEndEpochMs']);
        self::assertSame(32, (int) $this->observer->fetchOne("SELECT count(*) FROM query_change_inbox WHERE family = 'notifications'"));
        $burst = $this->fixture->execute(['op' => 'burst', 'run' => 'owned', 'count' => 100, 'durationMs' => 0, 'sequence' => 2]);
        self::assertSame(100, $burst['capturedRows']);
        self::assertSame(132, array_sum(array_column($burst['users'], 'count')));
        $catalog = $this->fixture->execute(['op' => 'catalog', 'run' => 'owned', 'albums' => 12, 'songs' => 10]);
        self::assertSame(12, $catalog['albums']);
        $sample = $this->fixture->execute(['op' => 'sample', 'run' => 'owned']);
        self::assertSame(['albums' => 12, 'songs' => 10], $sample['catalog']);
        $changed = $this->fixture->execute(['op' => 'change', 'run' => 'owned', 'target' => 'album', 'value' => 'Remote title']);
        self::assertSame('Remote title', $changed['entity']['title']);
        $this->fixture->execute(['op' => 'authority', 'run' => 'owned', 'target' => 'library', 'userIndex' => 1]);
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM user_library_access WHERE library_id = ? AND user_id = ?', [$owned['library']['id'], $owned['users'][1]['id']]));
        $this->fixture->execute(['op' => 'authority', 'run' => 'owned', 'target' => 'library', 'userIndex' => 1, 'grant' => true]);
        $held = $this->fixture->execute(['op' => 'projector', 'run' => 'owned', 'hold' => true]);
        self::assertTrue($held['held']);
        self::assertSame(0, $projector->project());
        self::assertSame('Remote title', $this->observer->fetchOne('SELECT title FROM albums WHERE id = ?', [$owned['album']['id']]));
        $this->fixture->execute(['op' => 'ordinary', 'run' => 'owned', 'sequence' => 3]);
        $this->fixture->execute(['op' => 'projector', 'run' => 'owned', 'hold' => false]);
        $partial = $this->fixture->execute(['op' => 'cleanup', 'run' => 'owned', 'waitMs' => 0]);
        self::assertFalse($partial['complete']);
        self::assertTrue($partial['manifestRetained']);
        self::assertFileExists($this->root . '/owned/manifest.json');
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM users WHERE id = ?', [$owned['users'][0]['id']]));
        self::assertSame(1, (int) $this->observer->fetchOne('SELECT count(*) FROM users WHERE id = ?', [$other['users'][0]['id']]));
        self::assertFileExists($other['song']['path']);
        self::assertDirectoryExists($owned['mediaDirectory']);
        $this->drain($projector);
        // An interrupted producer may have left ownership recorded before writing media.
        unlink($owned['song']['path']);
        $complete = $this->fixture->execute(['op' => 'cleanup', 'run' => 'owned', 'waitMs' => 0]);
        self::assertTrue($complete['complete']);
        self::assertSame(0, $complete['survivors']);
        self::assertDirectoryDoesNotExist($this->root . '/owned');
        self::assertDirectoryDoesNotExist($owned['mediaDirectory']);
        self::assertFileExists($other['song']['path']);
        self::assertNotEmpty($this->observer->fetchAllAssociative('SELECT * FROM query_scope_revision'), 'Cleanup retains revision tombstones.');
        $this->fixture->execute(['op' => 'cleanup', 'run' => 'other', 'waitMs' => 0]);
        $this->drain($projector);
        self::assertTrue($this->fixture->execute(['op' => 'cleanup', 'run' => 'other', 'waitMs' => 0])['complete']);
        self::assertDirectoryExists($this->mediaRoot);
    }

    private function drain(DoctrineLiveChangeProjector $projector): void
    {
        for ($i = 0; $i < 100 && $projector->project() !== 0; ++$i) {
        }
        self::assertSame(0, (int) $this->observer->fetchOne('SELECT count(*) FROM query_change_inbox'));
    }

    public function testPreparedSourcesAndCleanupFollowTheRealScanDeliveryContracts(): void
    {
        $stream = [];
        $delayed = [];
        $delayError = null;
        $this->fixture = new \LiveCapacityFixture($this->writer, $this->root, $this->mediaRoot, $this->queueFactory($stream, $delayed, $delayError));
        $owned = $this->fixture->execute(['op' => 'create', 'run' => 'scan-owned']);
        $other = $this->fixture->execute(['op' => 'create', 'run' => 'scan-other']);
        $this->writer->insert('system_settings', ['key' => 'lyrics.auto_fetch', 'value' => 'true']);
        $originalHash = hash_file('sha256', $owned['song']['path']);
        $prepared = $this->fixture->execute(['op' => 'prepare-scan', 'run' => 'scan-owned']);
        self::assertSame($originalHash, hash_file('sha256', $owned['song']['path']));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM songs WHERE path = ?', [$prepared['path']]));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM library_file_index WHERE path = ?', [$prepared['path']]));
        $metadata = (new WavReader(new NullLogger()))->read($prepared['path']);
        self::assertSame($prepared['title'], $metadata->getTitle());
        self::assertSame('Live album 00000', $metadata->getAlbum());
        self::assertNull($metadata->getArtist());
        self::assertSame([], $metadata->getGenre());
        self::assertSame($prepared['hash'], hash_file('xxh3', $prepared['path']));
        self::assertNotSame($prepared['hash'], hash('xxh3', \ScanFixture::taggedWave($prepared['title'], 'Live album 00000', 'another-run')));
        $handler = new \ReflectionClass(FilesDiscoveredHandler::class)->newInstanceWithoutConstructor();
        $lyricsReader = new \ReflectionMethod(FilesDiscoveredHandler::class, 'findLyricsFile');
        foreach ([$owned['song']['path'], $prepared['path']] as $audio) {
            $lyrics = $lyricsReader->invoke($handler, $audio);
            self::assertIsString($lyrics);
            self::assertStringContainsString('[00:00.00]Live owned lyrics scan-owned', $lyrics);
        }
        self::assertSame(99, (int) $this->writer->fetchOne('SELECT count(*) FROM notification_preferences WHERE NOT enabled'));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM notification_preferences WHERE user_id = ?', [$other['users'][0]['id']]));
        self::assertSame('true', $this->writer->fetchOne('SELECT value::text FROM system_settings WHERE key = ?', ['lyrics.auto_fetch']));

        $codec = new JsonMessageCodec([new LibraryMessagePayloadCodec(), new MetadataMessagePayloadCodec(), new NotificationMessagePayloadCodec()]);
        $serializer = new JsonTransportSerializer($codec);
        $claim = new Uuid();
        $scanJob = $this->monitored($codec, ScanLibraryCommand::forSlug($owned['library']['slug'], false, $claim), 'running');
        $this->writer->update('libraries', ['claim_id' => (string) $claim, 'claim_expires_at' => '2099-10-30 12:00:00+00', 'claim_kind' => 'scan', 'scan_status' => 'scanning'], ['id' => $owned['library']['id']]);
        $otherMessage = ScanLibraryCommand::forSlug($other['library']['slug']);
        $otherJob = $this->monitored($codec, $otherMessage, 'running');
        $file = new DiscoveredFile($prepared['path'], 'scan-owned/track.wav', 'wav', filesize($prepared['path']), filemtime($prepared['path']), $prepared['hash']);
        $ingest = new FilesDiscovered(Uuid::fromString($owned['library']['id']), 'music', dirname($prepared['path']), [$file]);
        $ingestJob = $this->monitored($codec, $ingest, 'finished');
        $coverJob = $this->monitored($codec, new ExtractAlbumCoverCommand(Uuid::fromString($owned['album']['id'])), 'finished');
        $notification = new PublicId();
        $this->writer->insert('notifications', ['id' => (string) new Uuid(), 'public_id' => (string) $notification, 'user_id' => $owned['users'][0]['id'], 'category' => 'background_jobs', 'event_type' => 'library.scan_completed', 'title' => 'Scan complete', 'body' => 'Owned', 'reference_data' => json_encode(['library_id' => $owned['library']['id']], JSON_THROW_ON_ERROR)]);
        $deliveryMessage = new SendPushCommand(Uuid::fromString($owned['users'][0]['id']), NotificationCategory::BackgroundJobs, 'Owned', 'Owned', (string) $notification);
        $deliveryJob = $this->monitored($codec, $deliveryMessage, 'finished');
        $event = new LibraryDirectoryIndexed($owned['library']['id'], 'music', dirname($prepared['path']), [['absolute_path' => $file->absolutePath, 'relative_path' => $file->relativePath, 'extension' => $file->extension, 'size' => $file->size, 'modified_at' => $file->modifiedAt, 'hash' => $file->hash]]);
        $this->writer->insert('domain_event_outbox', ['event_class' => $event::class, 'event_name' => $event->eventName(), 'payload' => json_encode($event->toPayload(), JSON_THROW_ON_ERROR), 'created_at' => '2026-10-30 12:00:00+00']);
        $eventId = (int) $this->writer->fetchOne('SELECT id FROM domain_event_outbox WHERE payload->>\'library_id\' = ?', [$owned['library']['id']]);
        $otherEvent = new LibraryDirectoryIndexed($other['library']['id'], 'music', $other['library']['path'], []);
        $this->writer->insert('domain_event_outbox', ['event_class' => $otherEvent::class, 'event_name' => $otherEvent->eventName(), 'payload' => json_encode($otherEvent->toPayload(), JSON_THROW_ON_ERROR), 'created_at' => '2026-10-30 12:00:00+00']);
        $this->writer->insert('domain_event_outbox_receipt', ['outbox_id' => $eventId, 'consumer' => 'scan-test']);
        $this->writer->insert('domain_event_outbox_delivery', ['channel' => 'push', 'notification_id' => (string) $notification, 'payload' => $codec->encode($deliveryMessage)]);
        $deliveryId = (int) $this->writer->fetchOne('SELECT id FROM domain_event_outbox_delivery WHERE notification_id = ?', [(string) $notification]);
        $pending = $this->fixture->execute(['op' => 'cleanup', 'run' => 'scan-owned', 'waitMs' => 0]);
        self::assertFalse($pending['complete']);
        self::assertTrue($pending['manifestRetained']);
        self::assertCount(4, $pending['scan']['jobs']);
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM users WHERE id = ?', [$owned['users'][0]['id']]));
        self::assertFileExists($prepared['sidecars'][0]);
        self::assertFileExists($prepared['path']);

        $this->writer->update('libraries', ['claim_id' => null, 'claim_expires_at' => null, 'claim_kind' => null, 'scan_status' => null], ['id' => $owned['library']['id']]);
        $this->writer->update('job_monitors', ['status' => 'failed'], ['id' => $scanJob['id']]);
        $this->writer->update('domain_event_outbox', ['relayed_at' => '2026-10-30 12:01:00+00'], ['id' => $eventId]);
        $this->writer->update('domain_event_outbox_delivery', ['relayed_at' => '2026-10-30 12:01:00+00'], ['id' => $deliveryId]);
        self::assertFalse($this->fixture->execute(['op' => 'scan-status', 'run' => 'scan-owned', 'requestId' => 'failed-job'])['drained']);
        $this->writer->update('job_monitors', ['status' => 'finished'], ['id' => $scanJob['id']]);
        $this->writer->update('domain_event_outbox', ['dead_lettered_at' => '2026-10-30 12:01:00+00'], ['id' => $eventId]);
        self::assertFalse($this->fixture->execute(['op' => 'scan-status', 'run' => 'scan-owned', 'requestId' => 'dead-event'])['drained']);
        $this->writer->update('domain_event_outbox', ['dead_lettered_at' => null], ['id' => $eventId]);
        self::assertTrue($this->fixture->execute(['op' => 'scan-status', 'run' => 'scan-owned', 'requestId' => 'empty-transports'])['drained']);
        $queuedJob = new PublicId();
        $ownedEnvelope = $serializer->encode(new Envelope($ingest, [new JobIdStamp($queuedJob)]));
        $otherEnvelope = $serializer->encode(new Envelope($otherMessage, [new JobIdStamp(new PublicId())]));
        $stream = ['1-0' => ['message' => json_encode($ownedEnvelope, JSON_THROW_ON_ERROR)], '2-0' => ['message' => json_encode($otherEnvelope, JSON_THROW_ON_ERROR)]];
        $delayed = [json_encode($ownedEnvelope + ['uniqid' => 'delay-owned'], JSON_THROW_ON_ERROR) => 1.0];
        $queued = $this->fixture->execute(['op' => 'scan-status', 'run' => 'scan-owned', 'requestId' => 'queued']) ;
        self::assertFalse($queued['drained']);
        self::assertCount(2, $queued['queue']);
        self::assertSame([(string) $queuedJob, (string) $queuedJob], array_column($queued['queue'], 'jobId'));
        $manifest = json_decode(file_get_contents($owned['directory'] . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($queued['queue'], $manifest['scan']['history']['queue']);
        $stream = ['2-0' => ['message' => json_encode($otherEnvelope, JSON_THROW_ON_ERROR)]];
        $delayed = [json_encode($otherEnvelope + ['uniqid' => 'delay-other'], JSON_THROW_ON_ERROR) => 1.0];
        $this->writer->insert('failed_messages', ['body' => $ownedEnvelope['body'], 'headers' => json_encode($ownedEnvelope['headers'], JSON_THROW_ON_ERROR), 'queue_name' => 'failed', 'created_at' => '2026-10-30 12:00:00+00', 'available_at' => '2026-10-30 12:00:00+00']);
        $failureId = (int) $this->writer->fetchOne('SELECT id FROM failed_messages');
        $failed = $this->fixture->execute(['op' => 'cleanup', 'run' => 'scan-owned', 'waitMs' => 0]);
        self::assertFalse($failed['complete']);
        self::assertCount(1, $failed['scan']['failures']);
        $this->writer->delete('failed_messages', ['id' => $failureId]);
        self::assertFalse($this->fixture->execute(['op' => 'scan-status', 'run' => 'scan-owned', 'requestId' => 'monitor-not-yet-recorded'])['drained']);
        $receivedJob = $this->monitored($codec, $ingest, 'finished');
        $this->writer->update('job_monitors', ['job_id' => (string) $queuedJob], ['id' => $receivedJob['id']]);
        $delayError = 'WRONGTYPE Operation against a key holding the wrong kind of value';
        $unreadableQueue = $this->fixture->execute(['op' => 'cleanup', 'run' => 'scan-owned', 'waitMs' => 0]);
        self::assertFalse($unreadableQueue['complete']);
        self::assertStringContainsString('Redis delay observation failed', implode(' ', $unreadableQueue['scan']['pending']));
        self::assertFileExists($prepared['path']);
        $delayError = null;
        self::assertTrue($this->fixture->execute(['op' => 'scan-status', 'run' => 'scan-owned', 'requestId' => 'drained'])['drained']);
        $this->observer->beginTransaction();
        $this->observer->executeQuery('SELECT id FROM job_monitors WHERE id = ? FOR UPDATE', [$scanJob['id']])->free();
        $this->writer->executeStatement("SET statement_timeout = '500ms'");
        try {
            $this->fixture->execute(['op' => 'cleanup', 'run' => 'scan-owned', 'waitMs' => 0]);
            self::fail('Cleanup must retain ownership when an owned terminal row is locked.');
        } catch (DriverException $error) {
            self::assertSame('55P03', $error->getSQLState());
        } finally {
            $this->observer->rollBack();
            $this->writer->executeStatement("SET statement_timeout = '0'");
        }
        self::assertFileExists($owned['directory'] . '/manifest.json');
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM libraries WHERE id = ?', [$owned['library']['id']]));
        $this->fixture->execute(['op' => 'cleanup', 'run' => 'scan-owned', 'waitMs' => 0]);
        foreach ([$scanJob, $ingestJob, $coverJob, $deliveryJob, $receivedJob] as $job) {
            self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM job_monitors WHERE id = ?', [$job['id']]));
        }
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM job_monitors WHERE id = ?', [$otherJob['id']]));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM domain_event_outbox WHERE id = ?', [$eventId]));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM domain_event_outbox_receipt WHERE outbox_id = ?', [$eventId]));
        self::assertSame(0, (int) $this->writer->fetchOne('SELECT count(*) FROM domain_event_outbox_delivery WHERE id = ?', [$deliveryId]));
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM domain_event_outbox WHERE payload->>\'library_id\' = ?', [$other['library']['id']]));
        $this->drain(new DoctrineLiveChangeProjector($this->observer));
        self::assertTrue($this->fixture->execute(['op' => 'cleanup', 'run' => 'scan-owned', 'waitMs' => 0])['complete']);
        self::assertDirectoryDoesNotExist(dirname($prepared['path']));
        self::assertFileDoesNotExist($prepared['sidecars'][0]);
        self::assertFileExists($other['song']['path']);
        self::assertDirectoryExists($this->mediaRoot);
        self::assertCount(1, $stream);
        self::assertCount(1, $delayed);
    }

    /**
     * @param array<string, array<string, string>> $stream
     * @param array<string, float> $delayed
     */
    private function queueFactory(array &$stream, array &$delayed, ?string &$delayError): RedisClientFactory
    {
        $lastError = null;
        $redis = $this->createStub(\Redis::class);
        $redis->method('xRange')->willReturnCallback(static function () use (&$stream): array {
            return $stream;
        });
        $redis->method('clearLastError')->willReturnCallback(static function () use (&$lastError): bool {
            $lastError = null;
            return true;
        });
        $redis->method('getLastError')->willReturnCallback(static function () use (&$lastError): ?string {
            return $lastError;
        });
        $redis->method('zScan')->willReturnCallback(static function (string $key, ?int &$cursor) use (&$delayed, &$delayError, &$lastError): array|false {
            $cursor = 0;
            if ($delayError !== null) {
                $lastError = $delayError;
                return false;
            }
            return $delayed === [] ? false : $delayed;
        });
        $factory = $this->createStub(RedisClientFactory::class);
        $factory->method('borrow')->willReturnCallback(static fn(callable $callback): mixed => $callback($redis));
        return $factory;
    }

    /** @return array{id: string, jobId: string} */
    private function monitored(JsonMessageCodec $codec, object $message, string $status): array
    {
        $id = (string) new Uuid();
        $job = (string) new PublicId();
        $this->writer->insert('job_monitors', ['id' => $id, 'job_id' => $job, 'name' => 'Fixture scan chain', 'queue' => 'async', 'status' => $status, 'data' => $codec->encode($message), 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        return ['id' => $id, 'jobId' => $job];
    }

    public function testScanPreparationPreservesGlobalSettingsAndRejectsNetworkDestinations(): void
    {
        $owned = $this->fixture->execute(['op' => 'create', 'run' => 'scan-preconditions']);
        $this->writer->insert('system_settings', ['key' => 'metadata.auto_sync', 'value' => 'true']);
        try {
            $this->fixture->execute(['op' => 'prepare-scan', 'run' => 'scan-preconditions']);
            self::fail('Enabled external metadata must stop preparation.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('metadata.auto_sync=false', $error->getMessage());
        }
        self::assertSame('true', $this->writer->fetchOne('SELECT value::text FROM system_settings WHERE key = ?', ['metadata.auto_sync']));
        $this->writer->update('system_settings', ['value' => 'false'], ['key' => 'metadata.auto_sync']);
        $webhook = (string) new Uuid();
        $this->writer->insert('webhooks', ['id' => $webhook, 'public_id' => (string) new PublicId(), 'url' => 'https://baander.app/fixture-hook', 'category_filter' => '["background_jobs"]', 'encrypted_secret' => 'fixture-owned-unused', 'created_at' => '2026-10-30 12:00:00+00', 'updated_at' => '2026-10-30 12:00:00+00']);
        try {
            $this->fixture->execute(['op' => 'prepare-scan', 'run' => 'scan-preconditions']);
            self::fail('An actual matching webhook must stop preparation.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no global/background_jobs webhooks', $error->getMessage());
        }
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM webhooks WHERE id = ?', [$webhook]));
        self::assertFileDoesNotExist($owned['library']['path'] . '/owned.lrc');
        $this->writer->delete('webhooks', ['id' => $webhook]);
        $this->fixture->execute(['op' => 'prepare-scan', 'run' => 'scan-preconditions']);
        $unobserved = $this->fixture->execute(['op' => 'cleanup', 'run' => 'scan-preconditions', 'waitMs' => 0]);
        self::assertFalse($unobserved['complete']);
        self::assertTrue($unobserved['manifestRetained']);
        self::assertStringContainsString('without configured Redis', implode(' ', $unobserved['scan']['pending']));
        self::assertSame(1, (int) $this->writer->fetchOne('SELECT count(*) FROM libraries WHERE id = ?', [$owned['library']['id']]));
    }
}
