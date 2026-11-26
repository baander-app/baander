<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\LogViewer;

use App\Shared\Application\LogViewer\LogQuery;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Infrastructure\LogViewer\LogIndex;
use App\Shared\Infrastructure\LogViewer\LogNormalizer;
use App\Shared\Infrastructure\LogViewer\LogStorageLimitException;
use PHPUnit\Framework\TestCase;

final class LogIndexTest extends TestCase
{
    public function testReplayIdentityPagingFrozenSnapshotAndLiteralSearch(): void
    {
        $index = new LogIndex(':memory:');
        $index->upsertSource(['id' => 'a', 'name' => 'app', 'status' => 'following']);
        $record = (new LogNormalizer())->normalize('foobar %_ .*', ['timestamp' => '2026-11-20T10:00:00Z']);
        $id = $index->append('a', 'boot1', 1, $record, ['offset' => 10]);
        self::assertSame($id, $index->append('a', 'boot1', 1, $record));
        $index->append('a', 'boot1', 2, $record);
        foreach (['oo', '%_', '.*', 'f'] as $search) {
            self::assertCount(2, $index->query(new LogQuery(search: $search))['items']);
        }
        $page = $index->query(new LogQuery(limit: 1));
        $index->append('a', 'boot2', 1, $record);
        $next = $index->query(new LogQuery(limit: 1, cursor: $page['nextCursor']));
        self::assertCount(1, $next['items']);
        self::assertNotSame($page['items'][0]['id'], $next['items'][0]['id']);
        self::assertNull($next['nextCursor']);
        self::assertSame(['offset' => 10], $index->checkpoint('a'));
    }
    public function testChangedFilterAndExpiredSnapshotAreRejected(): void
    {
        $index = new LogIndex(':memory:');
        $record = (new LogNormalizer())->normalize('x', ['timestamp' => '2026-11-20T10:00:00Z']);
        $index->append('a', '1', 1, $record);
        $index->append('a', '1', 2, $record);
        $page = $index->query(new LogQuery(limit: 1));
        $index->prune('2026-11-21T00:00:00Z');
        $this->expectException(InvalidInputException::class);
        $index->query(new LogQuery(limit: 1, cursor: $page['nextCursor']));
    }
    public function testFtsCandidatesAndSourceCoverageRemainLiteral(): void
    {
        $index = new LogIndex(':memory:');
        $index->upsertSource(['id' => 'a', 'status' => 'following']);
        $index->upsertSource(['id' => 'b', 'status' => 'unreachable']);
        $record = (new LogNormalizer())->normalize('foobar .*! AbC åäö', ['timestamp' => '2026-11-20T10:00:00Z']);
        $index->append('a', 'boot', 1, $record);
        foreach (['bar', 'AbC', 'åäö', '.*!'] as $search) {
            $page = $index->query(new LogQuery(sources: ['a'], search: $search));
            self::assertCount(1, $page['items']);
            self::assertTrue($page['coverage']['complete']);
        }
        self::assertCount(0, $index->query(new LogQuery(search: 'abc'))['items']);
        self::assertFalse($index->query(new LogQuery(sources: ['missing']))['coverage']['complete']);
    }

    public function testCursorRejectsChangedFiltersAndRetentionBudgetExpiresIt(): void
    {
        $index = new LogIndex(':memory:');
        $record = (new LogNormalizer())->normalize('foobar');
        $index->append('a', '1', 1, $record);
        $index->append('a', '1', 2, $record);
        $page = $index->query(new LogQuery(limit: 1));
        try {
            $index->query(new LogQuery(search: 'foo', cursor: $page['nextCursor']));
            self::fail('Changed filter accepted.');
        } catch (InvalidInputException $exception) {
            self::assertStringContainsString('filters changed', $exception->getMessage());
        }
        self::assertSame(2, $index->pruneToSize(0));
        self::assertCount(0, $index->query(new LogQuery())['items']);
    }

    public function testReadOnlyConnectionReadsWalAndRefusesWrites(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-log-index-');
        try {
            $writer = new LogIndex($path);
            $writer->append('a', '1', 1, (new LogNormalizer())->normalize('visible to readers'));
            $reader = new LogIndex($path, true);
            self::assertCount(1, $reader->query(new LogQuery())['items']);
            $this->expectException(\Exception::class);
            $reader->append('a', '1', 2, (new LogNormalizer())->normalize('denied'));
        } finally {
            unset($reader, $writer);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    public function testPhysicalStorageCapIncludesWalAndFullTextIndex(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-log-cap-');
        try {
            $index = new LogIndex($path, maxStorageBytes: 262144);
            $index->upsertSource(['id' => 'a', 'status' => 'healthy']);
            for ($sequence = 1; $sequence <= 6; ++$sequence) {
                $record = (new LogNormalizer())->normalize(str_repeat('retained log message ', 1500));
                $index->append('a', 'boot', $sequence, $record);
                self::assertLessThanOrEqual(262144, $index->storageBytes());
            }
            self::assertNotNull($index->sources()['items'][0]['retainedFrom']);
        } finally {
            unset($index);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testBusyReaderPreventsSilentStorageGrowth(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-log-busy-');
        try {
            $index = new LogIndex($path, maxStorageBytes: 262144);
            $index->append('a', 'boot', 1, (new LogNormalizer())->normalize('initial'));
            $reader = new \SQLite3($path, SQLITE3_OPEN_READONLY);
            $reader->exec('BEGIN');
            $reader->querySingle('SELECT COUNT(*) FROM logs');
            try {
                $longRecord = (new LogNormalizer())->normalize(str_repeat('long log entry ', 10000));
                $index->append('a', 'boot', 2, $longRecord);
                self::fail('Blocked reclamation must reject ingestion.');
            } catch (LogStorageLimitException $exception) {
                self::assertStringContainsString('ingestion is suspended', $exception->getMessage());
            }
            $reader->exec('COMMIT');
            $index->pruneToSize(262144);
            self::assertLessThanOrEqual(262144, $index->storageBytes());
        } finally {
            unset($reader, $index);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    public function testRegisteredSourceCheckpointAdvancesWithoutRetainingExcludedRecords(): void
    {
        $index = new LogIndex(':memory:');
        $index->upsertSource(['id' => 'gateway', 'status' => 'healthy']);
        $checkpoint = ['timestamp' => '2026-11-20T10:00:00Z', 'boundary' => ['sha256' => 2], 'sequence' => 2];
        $index->saveCheckpoint('gateway', $checkpoint);
        self::assertSame($checkpoint, $index->checkpoint('gateway'));
        self::assertCount(0, $index->query(new LogQuery())['items']);
        $this->expectException(InvalidInputException::class);
        $index->saveCheckpoint('unknown', $checkpoint);
    }
    public function testChannelInventoryIsDistinctSortedAndOnlyRetainedValues(): void
    {
        $index = new LogIndex(':memory:');
        foreach (['worker', 'app', 'worker', '', null, 'expired'] as $sequence => $channel) {
            $record = (new LogNormalizer())->normalize('message', ['timestamp' => $channel === 'expired' ? '2026-11-19T10:00:00Z' : '2026-11-20T10:00:00Z']);
            $record['channel'] = $channel;
            $index->append('a', 'boot', $sequence, $record);
        }
        self::assertSame(['app', 'expired', 'worker'], $index->sources()['channels']);
        $index->prune('2026-11-20T00:00:00Z');
        self::assertSame(['app', 'worker'], $index->sources()['channels']);
    }

    public function testChannelInventoryIncludesEveryDistinctRetainedChannel(): void
    {
        $index = new LogIndex(':memory:');
        for ($sequence = 0; $sequence < 257; ++$sequence) {
            $record = (new LogNormalizer())->normalize('message');
            $record['channel'] = sprintf('channel-%03d', 256 - $sequence);
            $index->append('a', 'boot', $sequence, $record);
        }
        $inventory = $index->sources();
        self::assertCount(257, $inventory['channels']);
        self::assertSame('channel-000', $inventory['channels'][0]);
        self::assertSame('channel-255', $inventory['channels'][255]);
        self::assertSame('channel-256', $inventory['channels'][256]);
    }
    public function testMultipleSelectedChannelsUseInclusiveFiltering(): void
    {
        $index = new LogIndex(':memory:');
        foreach (['app', 'worker', 'gateway', 'worker'] as $sequence => $channel) {
            $record = (new LogNormalizer())->normalize('message');
            $record['channel'] = $channel;
            $index->append('a', 'boot', $sequence, $record);
        }
        $page = $index->query(new LogQuery(channels: ['app', 'worker']));
        self::assertCount(3, $page['items']);
        self::assertSame(['worker', 'worker', 'app'], array_column($page['items'], 'channel'));
        self::assertCount(4, $index->query(new LogQuery())['items']);
        self::assertCount(0, $index->query(new LogQuery(channels: ['missing']))['items']);
    }
    public function testUnderBudgetPruningLeavesActiveReaderAndRetainedRecordsUntouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-log-under-budget-');
        try {
            $index = new LogIndex($path);
            $index->append('a', 'boot', 1, (new LogNormalizer())->normalize('before reader'));
            $reader = new \SQLite3($path, SQLITE3_OPEN_READONLY);
            $reader->exec('BEGIN');
            self::assertSame(1, $reader->querySingle('SELECT COUNT(*) FROM logs'));
            $index->append('a', 'boot', 2, (new LogNormalizer())->normalize('after reader'));
            $bytes = $index->storageBytes();
            self::assertLessThan(1073741824, $bytes);
            self::assertSame(0, $index->pruneToSize(1073741824));
            self::assertSame($bytes, $index->storageBytes());
            self::assertSame(1, $reader->querySingle('SELECT COUNT(*) FROM logs'));
            $reader->exec('COMMIT');
            self::assertCount(2, $index->query(new LogQuery())['items']);
        } finally {
            unset($reader, $index);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    public function testOverBudgetAppendsPauseBetweenBoundedReclamationPasses(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander-log-bounded-');
        try {
            $writer = new LogIndex($path, maxStorageBytes: 16777216);
            for ($sequence = 0; $sequence < 400; ++$sequence) {
                $normalizer = new LogNormalizer();
                $entry = str_repeat(sprintf('entry-%04d ', $sequence), 200);
                $writer->append('a', 'boot', $sequence, $normalizer->normalize($entry));
            }
            unset($writer);
            $index = new LogIndex($path, maxStorageBytes: 262144);
            $newRecord = (new LogNormalizer())->normalize('must wait for reclamation');
            try {
                $index->append('a', 'boot', 401, $newRecord);
                self::fail('The first bounded pass should leave collection paused.');
            } catch (LogStorageLimitException $exception) {
                self::assertStringContainsString('ingestion is suspended', $exception->getMessage());
            }
            $remaining = count($index->query(new LogQuery(limit: 500))['items']);
            self::assertGreaterThanOrEqual(144, $remaining);
            self::assertLessThan(400, $remaining);
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                try {
                    $index->append('a', 'boot', 401, $newRecord);
                    self::fail('The cooldown must reject appends while over budget.');
                } catch (LogStorageLimitException $exception) {
                    self::assertStringContainsString('bounded reclamation is pending', $exception->getMessage());
                }
            }
            self::assertCount($remaining, $index->query(new LogQuery(limit: 500))['items']);
            for ($pass = 0; $pass < 100 && $index->storageBytes() > 262144; ++$pass) {
                try {
                    $index->pruneToSize(262144);
                } catch (LogStorageLimitException $exception) {
                    self::assertStringContainsString('ingestion is suspended', $exception->getMessage());
                }
            }
            self::assertLessThanOrEqual(262144, $index->storageBytes());
            $id = $index->append('a', 'boot', 401, $newRecord);
            self::assertSame('must wait for reclamation', $index->detail($id)['message']);
            self::assertLessThanOrEqual(262144, $index->storageBytes());
        } finally {
            unset($index, $writer);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
