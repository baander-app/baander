<?php

namespace App\Console\Commands\QueueMonitor;

use App\Console\Commands\QueueMonitor\Concerns\HandlesDateInputs;
use App\Models\QueueMonitor;
use App\Modules\Queue\QueueMonitor\MonitorStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurgeOldMonitorsCommand extends Command
{
    use HandlesDateInputs;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue-monitor:purge {--before=} {--beforeDays=} {--beforeInterval=} {--only-succeeded} {--queue=} {--dry}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge monitor records';

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
            ->where('started_at', '<', $beforeDate);

        $queues = array_filter(explode(',', $this->option('queue') ?? ''));

        if (count($queues) > 0) {
            $query->whereIn('queue', array_map('trim', $queues));
        }

        if ($this->option('only-succeeded')) {
            $query->where('status', '=', MonitorStatus::Succeeded);
        }

        $count = $query->count();

        $this->info(
            sprintf('Purging %d jobs before %s.', $count, $beforeDate->format('Y-m-d H:i:s')),
        );

        $query->chunk(200, function (Collection $models, int $page) use ($count) {
            $this->info(
                sprintf('Deleted chunk %d / %d', $page, abs($count / 200)),
            );

            if ($this->option('dry')) {
                return;
            }

            DB::table((new \App\Models\QueueMonitor)->getModel()->getTable())
                ->whereIn('id', $models->pluck('id'))
                ->delete();
        });

        return 1;
    }
}
