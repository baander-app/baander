<?php

namespace App\Console\Commands\QueueMonitor;

use App\Console\Commands\QueueMonitor\Concerns\HandlesDateInputs;
use App\Models\QueueMonitor;
use App\Modules\Queue\QueueMonitor\MonitorStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarkJobsAsStaleCommand extends Command
{
    use HandlesDateInputs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue-monitor:stale {--before=} {--beforeDays=} {--beforeInterval=} {--dry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark jobs as stale within a given period';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $beforeDate = self::parseBeforeDate($this);
        if (null === $beforeDate) {
            $this->error('Needs at least --before or --beforeDays arguments');

            return 1;
        }

        $query = (new \App\Models\QueueMonitor)->getModel()
            ->newQuery()
            ->where('status', MonitorStatus::Running)
            ->where('started_at', '<', $beforeDate);

        $this->info(
            sprintf('Marking %d jobs after %s as stale', $count = $query->count(), $beforeDate->format('Y-m-d H:i:s')),
        );

        $query->chunk(500, function (Collection $models, int $page) use ($count) {
            $this->info(
                sprintf('Deleted chunk %d / %d', $page, abs($count / 200)),
            );

            if ($this->option('dry')) {
                return;
            }

            DB::table((new \App\Models\QueueMonitor)->getModel()->getTable())
                ->whereIn('id', $models->pluck('id'))
                ->update([
                    'status' => MonitorStatus::Stale,
                ]);
        });

        return 0;
    }
}
