<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Swoole;

use App\Shared\Domain\Model\Uuid;
use Swoole\Table;

/**
 * Shared booking table for segment dispatch coordination.
 *
 * Uses Swoole\Table (shared memory) for O(1) cross-coroutine lookups.
 * Both the encoding loop and the prefetcher check this table before
 * dispatching a segment to prevent double-dispatch.
 */
final class SegmentBookingTable
{
    private const COLUMN_SIZE = 16; // bytes for 'source' string column

    private Table $table;

    public function __construct(
        private readonly int $maxEntries = 4096,
    ) {
        $this->table = new Table($this->maxEntries);
        $this->table->column('source', Table::TYPE_STRING, self::COLUMN_SIZE);
        $this->table->column('timestamp', Table::TYPE_INT);
        $this->table->create();
    }

    /**
     * Book a segment for dispatch by the given source.
     *
     * @param string $source 'encoder' or 'prefetcher' — identifies who booked
     * @return bool true if booking succeeded (not already booked)
     */
    public function book(Uuid $jobId, int $segmentIndex, string $source = 'encoder'): bool
    {
        $key = $this->key($jobId, $segmentIndex);

        if ($this->table->exists($key)) {
            return false;
        }

        $this->table->set($key, [
            'source' => $source,
            'timestamp' => time(),
        ]);

        return true;
    }

    /**
     * Release a segment booking (after completion or error).
     */
    public function release(Uuid $jobId, int $segmentIndex): void
    {
        $key = $this->key($jobId, $segmentIndex);
        $this->table->del($key);
    }

    /**
     * Check if a segment is currently booked (in-flight).
     */
    public function isBooked(Uuid $jobId, int $segmentIndex): bool
    {
        return $this->table->exists($this->key($jobId, $segmentIndex));
    }

    /**
     * Get the source that booked a segment.
     */
    public function getBookingSource(Uuid $jobId, int $segmentIndex): ?string
    {
        $key = $this->key($jobId, $segmentIndex);
        if (!$this->table->exists($key)) {
            return null;
        }

        $row = $this->table->get($key);

        return $row['source'] ?? null;
    }

    /**
     * Clear all bookings.
     */
    public function clear(): void
    {
        foreach ($this->table as $key => $row) {
            $this->table->del($key);
        }
    }

    /**
     * Get the number of currently booked segments.
     */
    public function count(): int
    {
        return $this->table->count();
    }

    private function key(Uuid $jobId, int $segmentIndex): string
    {
        return sprintf('%s:%d', $jobId->toString(), $segmentIndex);
    }
}
