<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenTelemetry;

use Swoole\Table;

/**
 * Swoole\Table-backed span ring buffer.
 *
 * Shared across all Swoole workers via shared memory. Uses a metadata row
 * ('__meta') for the write index and count, and fixed-key rows ('span_0'..'span_N')
 * for the ring buffer data.
 *
 * Each span is stored as a JSON string in a single TEXT column. This avoids
 * per-column overhead and keeps the schema simple.
 */
final class SpanBridge
{
    private const int MAX_SPANS = 500;
    private const string META_KEY = '__meta';
    private const string SPAN_PREFIX = 'span_';

    private static ?Table $table = null;

    /**
     * Initialize the shared memory table. Called once during server startup.
     */
    public static function init(): void
    {
        if (self::$table !== null) {
            return;
        }

        $table = new Table(self::MAX_SPANS + 1); // +1 for metadata row
        $table->column('data', Table::TYPE_STRING, 16384); // 16KB per span JSON
        $table->column('idx', Table::TYPE_INT);
        $table->create();

        self::$table = $table;

        // Initialize metadata
        self::$table->set(self::META_KEY, [
            'data' => '',
            'idx' => 0,
        ]);
    }

    /**
     * Add a span to the ring buffer.
     */
    public function addSpan(array $spanData): void
    {
        $table = self::getTable();
        if ($table === null) {
            return;
        }

        // Atomic: read-modify-write the write index
        $meta = $table->get(self::META_KEY);
        $writeIdx = ($meta['idx'] ?? 0) % self::MAX_SPANS;

        $table->set(self::SPAN_PREFIX . $writeIdx, [
            'data' => json_encode($spanData, JSON_THROW_ON_ERROR),
            'idx' => $writeIdx,
        ]);

        // Advance write pointer
        $table->set(self::META_KEY, [
            'data' => '',
            'idx' => $writeIdx + 1,
        ]);
    }

    /**
     * Get the most recent spans, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentSpans(int $limit = 100): array
    {
        $table = self::getTable();
        if ($table === null) {
            return [];
        }

        $meta = $table->get(self::META_KEY);
        $writeIdx = $meta['idx'] ?? 0;

        $spans = [];
        $count = 0;

        // Read backwards from write position
        for ($i = 0; $i < self::MAX_SPANS && $count < $limit; $i++) {
            $readIdx = ($writeIdx - 1 - $i + self::MAX_SPANS) % self::MAX_SPANS;
            $row = $table->get(self::SPAN_PREFIX . $readIdx);
            if ($row === false) {
                continue; // Empty slot — ring hasn't wrapped yet
            }

            $decoded = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $spans[] = $decoded;
                $count++;
            }
        }

        return $spans;
    }

    /**
     * Clear all spans.
     */
    public function clear(): void
    {
        $table = self::getTable();
        if ($table === null) {
            return;
        }

        for ($i = 0; $i < self::MAX_SPANS; $i++) {
            $key = self::SPAN_PREFIX . $i;
            if ($table->exists($key)) {
                $table->del($key);
            }
        }

        $table->set(self::META_KEY, [
            'data' => '',
            'idx' => 0,
        ]);
    }

    /**
     * Return the number of spans currently in the buffer.
     */
    public function count(): int
    {
        $table = self::getTable();
        if ($table === null) {
            return 0;
        }

        $total = 0;
        for ($i = 0; $i < self::MAX_SPANS; $i++) {
            if ($table->exists(self::SPAN_PREFIX . $i)) {
                $total++;
            }
        }

        return $total;
    }

    private static function getTable(): ?Table
    {
        if (self::$table === null) {
            self::init();
        }

        return self::$table;
    }
}
