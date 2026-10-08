<?php

declare(strict_types=1);

namespace App\QoL\Interface\Console;

use App\QoL\Application\Port\QoLAdminPortInterface;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a QoL status report: one row per web server worker and a total row.
 *
 * @phpstan-import-type StatusReport from QoLAdminPortInterface
 */
final class QoLStatusTable
{
    /** @param StatusReport $report */
    public static function render(SymfonyStyle $io, array $report): void
    {
        $rows = array_map(static fn (array $worker): array => [
            $worker['worker_id'],
            $worker['state'],
            $worker['profile'],
            $worker['active_streams'],
            $worker['sample_count'],
            $worker['model_ready'] ? 'yes' : 'no',
            sprintf('%d%%', (int) round($worker['budget_cap'] * 100)),
        ], $report['workers']);
        $rows[] = new TableSeparator();
        $rows[] = ['Total', '', '', $report['total']['active_streams'], '', '', ''];

        $io->table(['Worker', 'State', 'Profile', 'Active streams', 'Samples', 'Model ready', 'Budget cap'], $rows);
    }
}
