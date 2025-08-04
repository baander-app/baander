<?php

namespace App\Modules\Development\Console\Commands;

use App\Modules\Filesystem\FileWatcher;
use App\Modules\Filesystem\Exceptions\InotifyException;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DevServerCommand extends Command
{
    protected $signature = 'dev:server {--stop : Stop the development server}';
    protected $description = 'Start development server with Octane, queue worker, and scheduler';

    private array $processes = [];
    private bool $running = true;
    private ?FileWatcher $fileWatcher = null;

    // Constants for better performance and maintainability
    private const string PHP_PATH = '/usr/local/bin/php';
    private const string PHP_ARGS = '-d variables_order=EGPCS';
    private const string ARTISAN_PATH = '/var/www/html/artisan';

    private const array WATCH_PATHS = [
        '/var/www/html/app',
        '/var/www/html/config',
        '/var/www/html/routes',
        '/var/www/html/database',
    ];

    private const array COLORS = [
        'octane'    => "\033[34m",     // Blue
        'queue'     => "\033[32m",      // Green
        'scheduler' => "\033[33m",  // Yellow
        'default'   => "\033[37m",     // White
    ];

    private const string RESET = "\033[0m";

    public function handle(): int
    {
        if ($this->option('stop')) {
            return $this->stopServer();
        }

        // Initialize file watcher
        $this->fileWatcher = new FileWatcher();

        if (!$this->fileWatcher->isAvailable()) {
            $this->error('File watching not available. Using fallback method.');
            return $this->handleWithoutFileWatching();
        }

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'pcntlSignalHandler']);
            pcntl_signal(SIGINT, [$this, 'pcntlSignalHandler']);
            pcntl_async_signals(true);
        }

        $this->info('Starting development server with file watching...');
        $this->info('Press Ctrl+C to stop all processes');
        $this->line('');

        $this->initializeFileWatcher();
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
        } else if (is_int($signalInfo)) {
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
        $signalName = match ($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
            SIGQUIT => 'SIGQUIT',
            default => "Signal $signal"
        };

        $this->line('');
        $this->info("Received $signalName, shutting down gracefully...");

        if ($previousExitCode !== 0 && $previousExitCode !== false) {
            $this->warn("Previous exit code: $previousExitCode");
        }

        $this->running = false;

        return 0;
    }

    private function initializeFileWatcher(): void
    {
        try {
            $this->fileWatcher->start();

            $totalWatches = 0;
            foreach (self::WATCH_PATHS as $path) {
                if (is_dir($path)) {
                    $watchCount = $this->fileWatcher->watch($path, ['IN_MODIFY', 'IN_CREATE', 'IN_DELETE'], true);
                    $totalWatches += $watchCount;
                }
            }

            // Add callback for PHP file changes
            $this->fileWatcher->onEvent(function (string $path, array $event) {
                $filename = $event['name'] ?? '';
                if (str_ends_with($filename, '.php')) {
                    $this->info("[File Watcher] PHP file changed: $path/$filename");
                    $this->scheduleQueueRestart();
                }
            });

            $this->info("[File Watcher] Watching $totalWatches directories for changes");
        } catch (InotifyException $e) {
            $this->error("Failed to initialize file watcher: " . $e->getMessage());
        }
    }

    private bool $queueRestartScheduled = false;

    private function scheduleQueueRestart(): void
    {
        $this->queueRestartScheduled = true;
    }

    private function checkForFileChanges(): bool
    {
        if (!$this->fileWatcher) {
            return false;
        }

        $eventCount = $this->fileWatcher->poll();

        if ($this->queueRestartScheduled) {
            $this->queueRestartScheduled = false;
            return true;
        }

        return false;
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
            self::PHP_PATH,
            self::PHP_ARGS,
            self::ARTISAN_PATH,
            'octane:start',
            '--watch',
            '--log-level=debug',
            '--host=0.0.0.0',
            '--port=8000',
            '--workers=auto',
            '--task-workers=auto',
            '--max-requests=250',
        ];

        return $this->createProcess($command);
    }

    private function createQueueProcess(): Process
    {
        $command = [
            self::PHP_PATH,
            self::PHP_ARGS,
            self::ARTISAN_PATH,
            'queue:work',
            'redis',
            '--memory=512',
            '--timeout=3600',
            '--tries=3',
            '--sleep=1',
            '--rest=0',
            '--max-jobs=100',
            '--max-time=300',
        ];

        return $this->createProcess($command);
    }

    private function createSchedulerProcess(): Process
    {
        // Create a wrapper script for the scheduler
        $schedulerScript = $this->createSchedulerScript();

        $process = new Process(['/bin/sh', $schedulerScript]);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        return $process;
    }

    private function createProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        return $process;
    }

    private function createSchedulerScript(): string
    {
        $scriptPath = storage_path('app/scheduler.sh');

        $script = <<<'SHELL'
#!/bin/sh

while true; do
    echo "[Scheduler] Running scheduled tasks..."
    /usr/local/bin/php -d variables_order=EGPCS /var/www/html/artisan schedule:run --verbose
    echo "[Scheduler] Sleeping for 15 seconds..."
    sleep 15
done
SHELL;

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        return $scriptPath;
    }

    /**
     * Monitor processes for output and status, handle signals and cleanup zombies
     */
    private function monitorProcesses(): void
    {
        foreach ($this->processes as $name => $process) {
            // Read and display output
            $this->displayProcessOutput($name, $process);

            // Check if process died
            if (!$process->isRunning()) {
                $exitCode = $process->getExitCode();

                if ($this->running) {
                    $this->error("[$name] Process died with exit code $exitCode, restarting...");
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
    }

    private function monitorProcessesWithOutput(): void
    {
        while ($this->running) {
            // Check for file changes
            if ($this->checkForFileChanges()) {
                $this->info('[Queue] Code changes detected, restarting worker...');
                $this->gracefullyRestartQueueProcess();
            }

            // Monitor all processes
            $this->monitorProcesses();

            usleep(100000); // 0.1 second
        }

        $this->stopAllProcesses();
    }

    private function displayProcessOutput(string $name, Process $process): void
    {
        // Get incremental output
        $output = $process->getIncrementalOutput();
        $errorOutput = $process->getIncrementalErrorOutput();

        // Display regular output with original colors intact
        if (!empty($output)) {
            $this->displayColoredOutput($name, $output, false);
        }

        // Display error output
        if (!empty($errorOutput)) {
            $this->displayColoredOutput($name, $errorOutput, true);
        }
    }

    private function displayColoredOutput(string $name, string $output, bool $isError = false): void
    {
        $color = self::COLORS[$name] ?? self::COLORS['default'];
        $prefix = $isError ? "[ERROR-$name]" : "[$name]";

        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (!empty($line)) {
                echo $color . $prefix . ' ' . $line . self::RESET . "\n";
            }
        }
    }

    private function restartProcess(string $name): void
    {
        if (isset($this->processes[$name])) {
            $this->processes[$name]->stop();
        }

        switch ($name) {
            case 'octane':
                $this->processes[$name] = $this->createOctaneProcess();
                break;
            case 'queue':
                $this->processes[$name] = $this->createQueueProcess();
                break;
            case 'scheduler':
                $this->processes[$name] = $this->createSchedulerProcess();
                break;
        }

        $this->processes[$name]->start();
        $this->info("[$name] Process restarted");
    }

    private function gracefullyRestartQueueProcess(): void
    {
        if (isset($this->processes['queue'])) {
            // Send SIGTERM for graceful shutdown
            $this->processes['queue']->signal(SIGTERM);

            // Wait a bit for graceful shutdown
            sleep(2);

            if ($this->processes['queue']->isRunning()) {
                // Force kill if still running
                $this->processes['queue']->stop();
            }

            // Restart the process
            $this->restartProcess('queue');
        }
    }

    private function stopAllProcesses(): void
    {
        $this->info('Stopping all processes...');

        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $this->info("Stopping $name...");
                $process->signal(SIGTERM);

                // Wait for graceful shutdown
                sleep(1);

                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }

        // Clean up file watcher
        $this->fileWatcher?->stop();

        $this->info('All processes stopped.');
    }

    private function stopServer(): int
    {
        $this->info('Stopping development server...');

        // Kill processes by name
        $processes = ['octane:start', 'queue:work', 'schedule:run'];

        foreach ($processes as $processName) {
            $command = ['pkill', '-f', $processName];
            $process = new Process($command);
            $process->run();

            if ($process->isSuccessful()) {
                $this->info("Stopped processes matching: $processName");
            }
        }

        return 0;
    }

    private function handleWithoutFileWatching(): int
    {
        $this->info('Starting development server without file watching...');
        $this->startAllProcesses();

        while ($this->running) {
            $this->monitorProcesses();
            sleep(1);
        }

        $this->stopAllProcesses();
        return 0;
    }
}