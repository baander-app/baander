<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Swoole\Table;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

/**
 * The health monitor's per-component alert state, and whether this server has ever
 * read a worker heartbeat.
 *
 * Implements Bootable: boot() creates both Swoole tables before the server forks its
 * workers, so the state survives worker reloads (ErrorRateWorkerReloader, dev HMR)
 * and resets only with the server. A row that does not exist reads healthy, so a
 * component already unhealthy when the server starts alerts once.
 *
 * Outside the server (console, tests) the tables are never created and the state is
 * held in this process only.
 */
final class HealthAlertTable implements Bootable, WorkerHeartbeatSightingInterface
{
    /** HealthCheckService::check() reports five components; this leaves room for more. */
    private const int SIZE = 32;
    private const string SEEN_KEY = 'seen';

    private ?Table $rows = null;
    private ?Table $sighting = null;

    /** @var array<string, HealthAlertRow> */
    private array $localRows = [];
    private bool $localSeen = false;

    public function boot(array $runtimeConfiguration = []): void
    {
        if ($this->rows !== null) {
            return;
        }

        // An overflow pool as large as the table gives every key a row.
        $rows = new Table(self::SIZE, 1.0);
        $rows->column('state', Table::TYPE_INT);
        $rows->column('unhealthy_since', Table::TYPE_INT);
        $rows->column('recovered_at', Table::TYPE_INT);
        $rows->create();

        $sighting = new Table(2, 1.0);
        $sighting->column(self::SEEN_KEY, Table::TYPE_INT);
        $sighting->create();

        $this->rows = $rows;
        $this->sighting = $sighting;
    }

    public function get(string $component): HealthAlertRow
    {
        if ($this->rows === null) {
            return $this->localRows[$component] ?? new HealthAlertRow();
        }

        $row = $this->rows->get($component);
        if (!is_array($row)) {
            return new HealthAlertRow();
        }
        $since = (int) $row['unhealthy_since'];
        $recovered = (int) $row['recovered_at'];

        return new HealthAlertRow(
            HealthAlertState::tryFrom((int) $row['state']) ?? HealthAlertState::Healthy,
            $since > 0 ? $since : null,
            $recovered > 0 ? $recovered : null,
        );
    }

    /** False when the row was not stored (no free row in the table). */
    public function save(string $component, HealthAlertRow $row): bool
    {
        if ($this->rows === null) {
            $this->localRows[$component] = $row;

            return true;
        }

        // set() warns when no row is free, and a throwing error handler would turn
        // that warning into an exception inside the monitor's tick; the result says enough.
        return @$this->rows->set($component, [
            'state' => $row->state->value,
            'unhealthy_since' => $row->unhealthySince ?? 0,
            'recovered_at' => $row->recoveredAt ?? 0,
        ]);
    }

    public function markSeen(): void
    {
        $this->localSeen = true;
        if ($this->sighting !== null && !$this->sighting->exists(self::SEEN_KEY)) {
            @$this->sighting->set(self::SEEN_KEY, [self::SEEN_KEY => 1]);
        }
    }

    public function wasSeen(): bool
    {
        return $this->localSeen || ($this->sighting?->exists(self::SEEN_KEY) ?? false);
    }
}
