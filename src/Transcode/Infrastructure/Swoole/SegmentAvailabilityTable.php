<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\SegmentAvailabilityInterface;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;
use Swoole\Table;

/**
 * Swoole\Table-backed segment-availability signaling.
 *
 * The table is shared memory across all Swoole worker processes: the
 * CPU-pool worker writes a row the instant FFmpeg finishes a segment, and
 * the HTTP worker reads it without IPC overhead. Swoole\Table is
 * process-shared and atomic for single-key writes — no lock is needed.
 *
 * Implements Bootable so the table is created before pool workers fork,
 * matching the CpuProcessPool pattern.
 */
final class SegmentAvailabilityTable implements SegmentAvailabilityInterface, Bootable
{
    private const string COLUMN_READY = 'ready';
    private const string COLUMN_PATH = 'path';
    /**
     * Default size: sized for ~4 concurrent streams × ~3 tiers × ~106 segments
     * per 10min video at 6s segments, with headroom. Long videos or higher
     * concurrency should override via DI.
     */
    private const int DEFAULT_TABLE_SIZE = 16384;
    private const int PATH_MAX_LENGTH = 512;

    private ?Table $table = null;

    public function __construct(
        private readonly int $tableSize = self::DEFAULT_TABLE_SIZE,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->table !== null) {
            return;
        }

        $this->table = self::makeTable($this->tableSize);
    }

    /**
     * Create and return a new Swoole\Table with the availability schema.
     *
     * Called during boot() before worker fork. The returned table is
     * process-shared so any worker that holds a reference can read/write.
     */
    public static function makeTable(int $size = 8192): Table
    {
        $table = new Table($size);
        $table->column(self::COLUMN_READY, Table::TYPE_INT, 1);
        $table->column(self::COLUMN_PATH, Table::TYPE_STRING, self::PATH_MAX_LENGTH);
        $table->create();

        return $table;
    }

    public function markReady(Uuid $jobId, string $tierKey, int $segmentIndex, string $path): void
    {
        $this->ensureBooted();

        $ok = $this->table->set($this->key($jobId, $tierKey, $segmentIndex), [
            self::COLUMN_READY => 1,
            self::COLUMN_PATH => $path,
        ]);

        // Swoole\Table::set returns false when the table is full. The write
        // is silently dropped and the controller falls back to file-stat
        // polling — degraded performance, not a correctness bug. Warn once
        // per process so overflow is observable without log spam.
        if ($ok === false && $this->logger !== null) {
            $this->logger->warning('SegmentAvailabilityTable full — segment availability row dropped', [
                'tableSize' => $this->tableSize,
                'jobId' => $jobId->toString(),
                'tierKey' => $tierKey,
                'segmentIndex' => $segmentIndex,
            ]);
        }
    }

    public function isReady(Uuid $jobId, string $tierKey, int $segmentIndex): ?string
    {
        $this->ensureBooted();

        $row = $this->table->get($this->key($jobId, $tierKey, $segmentIndex));

        if ($row === false || ($row[self::COLUMN_READY] ?? 0) !== 1) {
            return null;
        }

        $path = $row[self::COLUMN_PATH] ?? '';

        return $path !== '' ? $path : null;
    }

    public function clearJob(Uuid $jobId): void
    {
        $this->ensureBooted();

        $prefix = $jobId->toString() . ':';

        foreach ($this->table as $key => $_row) {
            if (str_starts_with($key, $prefix)) {
                $this->table->del($key);
            }
        }
    }

    private function key(Uuid $jobId, string $tierKey, int $segmentIndex): string
    {
        return sprintf('%s:%s:%d', $jobId->toString(), $tierKey, $segmentIndex);
    }

    private function ensureBooted(): void
    {
        if ($this->table === null) {
            $this->table = self::makeTable($this->tableSize);
        }
    }
}
