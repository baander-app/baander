<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Worker\Autoscaling;

final readonly class WorkloadHandler
{
    public function __invoke(Workload $message): void
    {
        $started = hrtime(true) / 1e9;
        $cpuTime = static function (): float {
            $usage = getrusage();
            return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
                + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
        };
        $cpuStarted = $cpuTime();
        if ($message->kind === 'cpu') {
            $until = $started + 2;
            $digest = str_repeat('autoscaling', 4096);
            do { $digest = hash('sha256', $digest . str_repeat('autoscaling', 4096)); }
            while (hrtime(true) / 1e9 < $until);
        } else {
            // A bounded blocking wait models I/O without an external host or service.
            $until = $started + 2;
            while (($remaining = $until - hrtime(true) / 1e9) > 0) {
                usleep((int) ceil($remaining * 1e6));
            }
        }
        $cpuSeconds = $cpuTime() - $cpuStarted;
        $directory = (string) getenv('BAANDER_WORKER_IPC_DIRECTORY') . '/' . getenv('BAANDER_WORKER_BOOT_ID');
        if (!is_dir($directory) || is_link($directory)) {
            throw new \RuntimeException('Missing private disposable boot directory.');
        }
        $receipt = ['batch' => $message->batch, 'sequence' => $message->sequence, 'started' => $started,
            'finished' => hrtime(true) / 1e9, 'kind' => $message->kind, 'cpuSeconds' => $cpuSeconds, 'pid' => getmypid(), 'slot' => getenv('BAANDER_WORKER_ID')];
        if (file_put_contents($directory . '/autoscaling-completions.jsonl', json_encode($receipt, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot record qualification completion.');
        }
    }
}
