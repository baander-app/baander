<?php

namespace App\Modules\Development\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DevServerCommand extends Command
{
    protected $signature = 'dev:server {--stop : Stop the development server}';
    protected $description = 'Start development server with Octane, queue worker, and scheduler';

    private array $processes = [];
    private bool $running = true;
    private string $phpPath = '/usr/local/bin/php';
    private string $phpArgs = '-d variables_order=EGPCS';

    public function handle(): int
    {
        if ($this->option('stop')) {
            return $this->stopServer();
        }

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'pcntlSignalHandler']);
            pcntl_signal(SIGINT, [$this, 'pcntlSignalHandler']);
            pcntl_async_signals(true);
        }

        $this->info('Starting development server...');
        $this->info('Press Ctrl+C to stop all processes');
        $this->line('');

        $this->startAllProcesses();
        $this->monitorProcessesWithOutput();

        return 0;
    }

    /**
     * Handle PCNTL signals - this is called by pcntl_signal
     */
    public function pcntlSignalHandler(int $signal, mixed $signalInfo = null): void
    {
        // Extract exit code from signal info if available
        $exitCode = 0;
        if (is_array($signalInfo) && isset($signalInfo['status'])) {
            $exitCode = $signalInfo['status'];
        } elseif (is_int($signalInfo)) {
            $exitCode = $signalInfo;
        }

        // Call the Symfony Command's handleSignal method with proper parameters
        $this->handleSignal($signal, $exitCode);
    }

    /**
     * Symfony Command's signal handler - matches parent signature
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): false|int
    {
        $signalName = match($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
            SIGQUIT => 'SIGQUIT',
            default => "Signal {$signal}"
        };

        $this->line('');
        $this->info("Received {$signalName}, shutting down gracefully...");

        if ($previousExitCode !== 0 && $previousExitCode !== false) {
            $this->warn("Previous exit code: {$previousExitCode}");
        }

        $this->running = false;

        return 0;
    }

    private function startAllProcesses(): void
    {
        // Start Octane server
        $this->processes['octane'] = $this->createOctaneProcess();
        $this->processes['octane']->start();
        $this->info('[Octane] Started on port 8000');

        // Start Queue worker
        $this->processes['queue'] = $this->createQueueProcess();
        $this->processes['queue']->start();
        $this->info('[Queue] Worker started');

        // Start Scheduler
        $this->processes['scheduler'] = $this->createSchedulerProcess();
        $this->processes['scheduler']->start();
        $this->info('[Scheduler] Started');

        $this->line('');
        $this->line('--- Process Output ---');
    }

    private function createOctaneProcess(): Process
    {
        $command = [
            $this->phpPath,
            $this->phpArgs,
            '/var/www/html/artisan',
            'octane:start',
            '--watch',
            '--log-level=debug',
            '--host=0.0.0.0',
            '--port=8000',
            '--workers=auto',
            '--task-workers=auto',
            '--max-requests=250'
        ];

        $process = new Process($command);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        return $process;
    }

    private function createQueueProcess(): Process
    {
        $command = [
            $this->phpPath,
            $this->phpArgs,
            '/var/www/html/artisan',
            'queue:listen',
            'redis',
            '--memory=512',
            '--timeout=3600',
            '--tries=3',
            '--sleep=0',
            '--rest=0'
        ];

        $process = new Process($command);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        return $process;
    }

    private function createSchedulerProcess(): Process
    {
        // Create a wrapper script for the scheduler
        $schedulerScript = $this->createSchedulerScript();

        $process = new Process(['bash', $schedulerScript]);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        return $process;
    }

    private function createSchedulerScript(): string
    {
        $scriptPath = storage_path('app/scheduler.sh');

        $script = <<<BASH
#!/usr/bin/env bash

while true; do
    echo "[Scheduler] Running scheduled tasks..."
    {$this->phpPath} {$this->phpArgs} /var/www/html/artisan schedule:run --verbose
    echo "[Scheduler] Sleeping for 60 seconds..."
    sleep 60
done
BASH;

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        return $scriptPath;
    }

    private function monitorProcessesWithOutput(): void
    {
        while ($this->running) {
            // Check each process for output and status
            foreach ($this->processes as $name => $process) {
                // Read and display output
                $this->displayProcessOutput($name, $process);

                // Check if process died
                if (!$process->isRunning()) {
                    $exitCode = $process->getExitCode();

                    if ($this->running) {
                        $this->error("[{$name}] Process died with exit code {$exitCode}, restarting...");
                        $this->restartProcess($name);
                    }
                }
            }

            // Handle signals and reap child processes
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Check for zombie processes
            if (function_exists('pcntl_waitpid')) {
                pcntl_waitpid(-1, $status, WNOHANG);
            }

            usleep(100000); // 0.1 second
        }

        $this->stopAllProcesses();
    }

    private function displayProcessOutput(string $name, Process $process): void
    {
        // Get incremental output
        $output = $process->getIncrementalOutput();
        $errorOutput = $process->getIncrementalErrorOutput();

        // Display regular output
        if (!empty($output)) {
            $lines = explode("\n", rtrim($output, "\n"));
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $this->formatProcessOutput($name, $line, 'info');
                }
            }
        }

        // Display error output
        if (!empty($errorOutput)) {
            $lines = explode("\n", rtrim($errorOutput, "\n"));
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $this->formatProcessOutput($name, $line, 'error');
                }
            }
        }
    }

    private function formatProcessOutput(string $name, string $line, string $type = 'info'): void
    {
        $color = match($type) {
            'error' => 'red',
            'warn' => 'yellow',
            default => 'white'
        };

        $timestamp = now()->format('H:i:s');

        $this->line("<fg={$color}>[{$timestamp}] [{$name}] {$line}</>");
    }

    private function restartProcess(string $name): void
    {
        // Stop the old process if it's still running
        if (isset($this->processes[$name]) && $this->processes[$name]->isRunning()) {
            $this->processes[$name]->stop(3, SIGTERM);
        }

        // Wait a moment before restarting
        sleep(1);

        // Create and start new process
        switch ($name) {
            case 'octane':
                $this->processes['octane'] = $this->createOctaneProcess();
                $this->processes['octane']->start();
                $this->info('[Octane] Restarted');
                break;

            case 'queue':
                $this->processes['queue'] = $this->createQueueProcess();
                $this->processes['queue']->start();
                $this->info('[Queue] Worker restarted');
                break;

            case 'scheduler':
                $this->processes['scheduler'] = $this->createSchedulerProcess();
                $this->processes['scheduler']->start();
                $this->info('[Scheduler] Restarted');
                break;
        }
    }

    private function stopAllProcesses(): void
    {
        $this->info('Stopping all processes...');

        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $this->info("[{$name}] Stopping...");

                // Try graceful shutdown first
                $process->stop(10, SIGTERM);

                // Force kill if still running
                if ($process->isRunning()) {
                    $this->warn("[{$name}] Force killing...");
                    $process->stop(3, SIGKILL);
                }

                $this->info("[{$name}] Stopped");
            }
        }

        // Clean up scheduler script
        $schedulerScript = storage_path('app/scheduler.sh');
        if (file_exists($schedulerScript)) {
            unlink($schedulerScript);
        }

        // Fallback: kill any remaining processes by name
        $this->killProcessesByPattern();

        $this->info('All processes stopped successfully');
    }

    private function killProcessesByPattern(): void
    {
        $patterns = [
            'octane:start',
            'queue:listen default,redis',
            'schedule:run'
        ];

        foreach ($patterns as $pattern) {
            exec("pkill -f '{$pattern}' 2>/dev/null");
        }
    }

    private function stopServer(): int
    {
        $this->info('Stopping development server...');

        // Kill processes by pattern
        $this->killProcessesByPattern();

        // Clean up scheduler script
        $schedulerScript = storage_path('app/scheduler.sh');
        if (file_exists($schedulerScript)) {
            unlink($schedulerScript);
        }

        $this->info('Development server stopped.');
        return 0;
    }
}