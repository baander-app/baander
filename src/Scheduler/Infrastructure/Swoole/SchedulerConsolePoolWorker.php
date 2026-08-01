<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Swoole;

use App\Shared\Infrastructure\Swoole\ProcessPool\ProcessPoolWorkerInterface;

/**
 * CPU pool worker that executes Symfony console commands in an isolated process.
 *
 * Runs without Symfony container — receives a serialized job payload with the
 * console command path and parameters, execs it, and returns the output.
 *
 * Since there is no container access, this uses proc_open() to run the
 * bin/console script directly.
 */
final class SchedulerConsolePoolWorker implements ProcessPoolWorkerInterface
{
    public function supportedTypes(): array
    {
        return ['scheduled_console'];
    }

    public function handle(string $payload): string
    {
        $job = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $command = $job['command'] ?? '';
        $parameters = $job['parameters'] ?? [];

        $consolePath = dirname(__DIR__, 4) . '/bin/console';

        $cmd = sprintf('php %s %s', escapeshellarg($consolePath), escapeshellarg($command));

        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $cmd .= ' ' . escapeshellarg((string) $value);
            } else {
                $cmd .= sprintf(' --%s=%s', $key, escapeshellarg((string) $value));
            }
        }

        $output = '';
        $exitCode = 0;

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            return json_encode([
                'success' => false,
                'error' => sprintf('Failed to start process for command: %s', $command),
            ], JSON_THROW_ON_ERROR);
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return json_encode([
                'success' => false,
                'error' => trim($stderr ?: $stdout),
                'exitCode' => $exitCode,
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'success' => true,
            'output' => trim($stdout),
        ], JSON_THROW_ON_ERROR);
    }
}
