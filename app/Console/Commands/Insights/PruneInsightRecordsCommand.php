<?php

namespace App\Console\Commands\Insights;

use App\Models\Insight\Gauge;
use App\Models\Insight\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PruneInsightRecordsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'insight:prune-insight-records {datetime : Parsable datetime format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete records older than the given time';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $time = Carbon::parse((string)$this->argument('datetime'));

        $this->info("Deleting messages and gauges older than {$time->toDateTimeString()}...");

        $messages = Message::query()
            ->where('created_at', '<=', $time)
            ->get();

        $messages->each(function (Message $message) {
            $message->gauges()->delete();
        });

        $count = Message::query()
            ->where('created_at', '<=', $time)
            ->delete();

        $count += Gauge::query()
            ->where('created_at', '<=', $time)
            ->delete();

        $this->info($count . ' ' . Str::plural('row') . ' deleted.');

        return 0;
    }
}
