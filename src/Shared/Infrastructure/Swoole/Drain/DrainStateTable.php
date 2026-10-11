<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Drain;

use App\Shared\Application\Port\ServerDrainStateInterface;
use Swoole\Table;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

/**
 * Whether the web server is draining, when the drain started, and which event workers have
 * finished their drain steps. Every process of the server reads and writes the same rows.
 *
 * Implements Bootable: boot() creates the Swoole\Table before the server forks, so the master,
 * the workers and reloaded workers share it. The master sets the drain row once, from
 * onBeforeShutdown. A worker reload never touches it.
 *
 * Instants are hrtime(true) nanoseconds: CLOCK_MONOTONIC, the same clock in every process.
 *
 * Outside the server (console, tests that do not boot it) the table does not exist: nothing is
 * draining, and a write throws.
 */
final class DrainStateTable implements Bootable, ServerDrainStateInterface
{
    private const string DRAIN_KEY = 'drain';
    private const string WORKER_KEY_PREFIX = 'worker:';
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

        // One drain row and one row per event worker id. Twice that, with an overflow pool as
        // large as the table (conflict proportion 1.0), gives every key a row.
        $workers = $this->serverConfiguration?->getWorkerCount() ?? 1;
        $table = new Table(max(self::MIN_ROWS, 2 * ($workers + 1)), 1.0);
        $table->column('started_at_ns', Table::TYPE_INT);
        $table->column('drained', Table::TYPE_INT);
        if (!$table->create()) {
            throw new \RuntimeException('Cannot create the drain state table.');
        }
        $this->table = $table;
    }

    /**
     * Records the start of the drain. Returns false, and changes nothing, when a drain has
     * already started.
     */
    public function begin(int $startedAtNs): bool
    {
        $table = $this->created();
        if ($table->exists(self::DRAIN_KEY)) {
            return false;
        }
        $this->set(self::DRAIN_KEY, ['started_at_ns' => $startedAtNs, 'drained' => 0]);

        return true;
    }

    public function isDraining(): bool
    {
        return $this->table?->exists(self::DRAIN_KEY) ?? false;
    }

    /** The hrtime(true) instant at which the drain started, or null when nothing is draining. */
    public function startedAtNs(): ?int
    {
        $row = $this->table?->get(self::DRAIN_KEY);

        return is_array($row) ? (int) $row['started_at_ns'] : null;
    }

    /** Records that the event worker with this id has finished its drain steps. */
    public function markWorkerDrained(int $workerId): void
    {
        $this->created();
        $this->set(self::WORKER_KEY_PREFIX . $workerId, ['started_at_ns' => 0, 'drained' => 1]);
    }

    /** Whether every event worker with an id below $eventWorkerCount has reported drained. */
    public function allWorkersDrained(int $eventWorkerCount): bool
    {
        if ($this->table === null) {
            return false;
        }
        for ($workerId = 0; $workerId < $eventWorkerCount; ++$workerId) {
            $row = $this->table->get(self::WORKER_KEY_PREFIX . $workerId);
            if (!is_array($row) || (int) $row['drained'] !== 1) {
                return false;
            }
        }

        return true;
    }

    private function created(): Table
    {
        return $this->table ?? throw new \LogicException('The drain state table is created when the web server boots.');
    }

    /** @param array<string, int> $row */
    private function set(string $key, array $row): void
    {
        // set() warns when no row is free; the result says enough.
        if (!@$this->created()->set($key, $row)) {
            throw new \RuntimeException(sprintf('Cannot store the drain state row "%s".', $key));
        }
    }
}
