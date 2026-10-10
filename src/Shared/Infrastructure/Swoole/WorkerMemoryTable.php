<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use Swoole\Table;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

/**
 * The real memory use of each HTTP worker, shared by every worker.
 *
 * Implements Bootable: boot() creates the Swoole\Table before the server forks its
 * workers, so it survives worker reloads. WorkerMemoryReporter writes one row per
 * HTTP worker id every UPDATE_INTERVAL_SECONDS. A row not updated for three
 * intervals belongs to a worker that exited or hangs, and is ignored. A reloaded
 * worker reuses its id and so its row.
 *
 * Outside the server (console, tests) the table is never created: record() stores
 * nothing and largest() finds no row.
 *
 * record() runs in a Swoole timer: it must not touch pooled services such as the
 * logger.
 */
final class WorkerMemoryTable implements Bootable
{
    public const int UPDATE_INTERVAL_SECONDS = 5;
    private const int STALE_AFTER_SECONDS = 3 * self::UPDATE_INTERVAL_SECONDS;
    private const int MIN_ROWS = 8;

    private ?Table $table = null;

    /** The container passes the server configuration; it is optional only for tests. */
    public function __construct(
        private readonly ?HttpServerConfiguration $serverConfiguration = null,
    ) {
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->table !== null) {
            return;
        }

        // Keys are the HTTP worker ids 0..worker_count-1. Twice the worker count,
        // and an overflow pool as large as the table, give every id a row.
        $workers = $this->serverConfiguration?->getWorkerCount() ?? 1;
        $table = new Table(max(self::MIN_ROWS, 2 * $workers), 1.0);
        $table->column('worker_id', Table::TYPE_INT);
        $table->column('bytes', Table::TYPE_INT);
        $table->column('updated_at', Table::TYPE_INT);
        $table->create();
        $this->table = $table;
    }

    /** Stores a worker's memory use; false when it was not stored (no table, or no free row). */
    public function record(int $workerId, int $bytes, int $now): bool
    {
        if ($this->table === null) {
            return false;
        }

        // set() warns when no row is free, and a throwing error handler would turn
        // that warning into an exception inside the timer; the result says enough.
        return @$this->table->set((string) $workerId, [
            'worker_id' => $workerId,
            'bytes' => $bytes,
            'updated_at' => $now,
        ]);
    }

    /**
     * The worker using the most memory among rows updated in the last three intervals.
     *
     * @return array{workerId: int, bytes: int}|null
     */
    public function largest(int $now): ?array
    {
        if ($this->table === null) {
            return null;
        }

        $largest = null;
        foreach ($this->table as $row) {
            if ($now - (int) $row['updated_at'] > self::STALE_AFTER_SECONDS) {
                continue;
            }
            $bytes = (int) $row['bytes'];
            if ($largest === null || $bytes > $largest['bytes']) {
                $largest = ['workerId' => (int) $row['worker_id'], 'bytes' => $bytes];
            }
        }

        return $largest;
    }
}
