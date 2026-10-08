<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenTelemetry;

use Swoole\Table;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

/**
 * Ring buffer of recent spans for the server diagnostics, in a Swoole\Table.
 *
 * The table is created in boot(), which the bundle calls before the HTTP workers
 * fork, so every worker writes to and reads from the same shared memory. A
 * process that never booted it (a console command, a functional test) has no
 * buffer: recorded spans are dropped and reads return nothing.
 *
 * A metadata row ('__meta') counts the spans ever written; its atomic increment
 * hands each writer its own slot ('span_0'..'span_N'). Each span is stored as a
 * JSON string in a single column.
 */
final class SpanBridge implements Bootable
{
    private const int MAX_SPANS = 500;
    private const int MAX_SPAN_BYTES = 16384;
    private const string META_KEY = '__meta';
    private const string SPAN_PREFIX = 'span_';

    private ?Table $table = null;

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->table !== null) {
            return;
        }

        $table = new Table(self::MAX_SPANS + 1); // +1 for the metadata row
        $table->column('data', Table::TYPE_STRING, self::MAX_SPAN_BYTES);
        $table->column('idx', Table::TYPE_INT);
        $table->create();
        $table->set(self::META_KEY, ['data' => '', 'idx' => 0]);

        $this->table = $table;
    }

    /**
     * Add a span to the ring buffer.
     *
     * @param array<string, mixed> $spanData
     */
    public function addSpan(array $spanData): void
    {
        if ($this->table === null) {
            return;
        }

        $written = $this->table->incr(self::META_KEY, 'idx');
        $slot = ($written - 1) % self::MAX_SPANS;
        $this->table->set(self::SPAN_PREFIX . $slot, [
            'data' => json_encode($spanData, JSON_THROW_ON_ERROR),
            'idx' => $slot,
        ]);
    }

    /**
     * Get the most recent spans, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function getRecentSpans(int $limit = 100): array
    {
        if ($this->table === null) {
            return [];
        }

        $written = (int) $this->table->get(self::META_KEY, 'idx');
        $spans = [];
        for ($i = 0; $i < min($written, self::MAX_SPANS) && count($spans) < $limit; $i++) {
            $row = $this->table->get(self::SPAN_PREFIX . (($written - 1 - $i) % self::MAX_SPANS));
            if ($row === false) {
                continue; // cleared while being read
            }

            $decoded = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $spans[] = $decoded;
            }
        }

        return $spans;
    }

    /**
     * Clear all spans.
     */
    public function clear(): void
    {
        if ($this->table === null) {
            return;
        }

        $this->table->set(self::META_KEY, ['data' => '', 'idx' => 0]);
        for ($i = 0; $i < self::MAX_SPANS; $i++) {
            $this->table->del(self::SPAN_PREFIX . $i);
        }
    }

    /**
     * Return the number of spans currently in the buffer.
     */
    public function count(): int
    {
        if ($this->table === null) {
            return 0;
        }

        return min((int) $this->table->get(self::META_KEY, 'idx'), self::MAX_SPANS);
    }
}
