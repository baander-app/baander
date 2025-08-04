<?php

namespace App\Modules\Development\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DevServerCommand extends Command
{
    protected $signature = 'dev:server {--stop : Stop the development server}';
    protected $description = 'Start development server with Octane, queue worker, and scheduler';

    private array $processes = [];
    private bool $running = true;

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

    private const int WATCH_MASK = IN_MODIFY | IN_CREATE | IN_DELETE | IN_MOVE;

    private const array COLORS = [
        'octane'    => "\033[34m",     // Blue
        'queue'     => "\033[32m",      // Green
        'scheduler' => "\033[33m",  // Yellow
        'default'   => "\033[37m",     // White
    ];

    private const string RESET = "\033[0m";

    /**
     * @var false|resource
     */
    private $inotifyResource;
    private array $watchDescriptors = [];

    public function handle(): int
    {
        if ($this->option('stop')) {
            return $this->stopServer();
        }

        // Check if inotify extension is available
        if (!extension_loaded('inotify')) {
            $this->error('inotify extension not available. Using fallback method.');
            return $this->handleWithoutInotify();
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
        $this->inotifyResource = inotify_init();
        stream_set_blocking($this->inotifyResource, false);

        foreach (self::WATCH_PATHS as $path) {
            if (is_dir($path)) {
                $this->addWatchRecursive($path);
            }
        }

        $this->info('[File Watcher] Watching ' . count($this->watchDescriptors) . ' directories for changes');
    }

    private function addWatchRecursive(string $path): void
    {
        $wd = inotify_add_watch($this->inotifyResource, $path, self::WATCH_MASK);

        if ($wd) {
            $this->watchDescriptors[$wd] = $path;
        }

        // Use more efficient directory iteration
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $wd = inotify_add_watch($this->inotifyResource, $file->getPathname(), self::WATCH_MASK);

                if ($wd) {
                    $this->watchDescriptors[$wd] = $file->getPathname();
                }
            }
        }
    }

    private function checkForFileChanges(): bool
    {
        $events = inotify_read($this->inotifyResource);

        if (!$events) {
            return false;
        }

        foreach ($events as $event) {
            $filename = $event['name'] ?? '';

            // Quick extension check without pathinfo
            if (str_ends_with($filename, '.php')) {
                $path = $this->watchDescriptors[$event['wd']] ?? 'unknown';
                $this->info("[File Watcher] PHP file changed: $path/$filename");
                return true;
            }
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
            $lines = explode("\n", rtrim($output, "\n"));
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $this->outputOriginalLine($name, $line);
                }
            }
        }

        // Display error output with original colors intact
        if (!empty($errorOutput)) {
            $lines = explode("\n", rtrim($errorOutput, "\n"));
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $this->outputOriginalLine($name, $line);
                }
            }
        }
    }

    private function outputOriginalLine(string $name, string $line): void
    {
        $timestamp = now()->format('H:i:s');
        $color = self::COLORS[$name] ?? self::COLORS['default'];

        // Single write operation for better performance
        $this->output->write("$color[$timestamp] [$name]" . self::RESET . " $line\n");
    }

    private function gracefullyRestartQueueProcess(): void
    {
        if (isset($this->processes['queue'])) {
            // Gracefully terminate the queue process
            $this->processes['queue']->signal(SIGTERM);

            // Wait up to 10 seconds for a graceful shutdown
            $timeout = 10;
            while ($this->processes['queue']->isRunning() && $timeout > 0) {
                usleep(500000); // 0.5 seconds
                $timeout--;
            }

            // Force kill if still running
            if ($this->processes['queue']->isRunning()) {
                $this->processes['queue']->signal(SIGKILL);
            }

            // Start new queue process
            $this->processes['queue'] = $this->createQueueProcess();
            $this->processes['queue']->start();
            $this->info('[Queue] Worker restarted successfully');
        }
    }

    private function restartProcess(string $name): void
    {
        if (!isset($this->processes[$name])) {
            return;
        }

        // Stop the existing process
        if ($this->processes[$name]->isRunning()) {
            $this->processes[$name]->signal(SIGTERM);

            // Wait for a graceful shutdown
            $timeout = 5;
            while ($this->processes[$name]->isRunning() && $timeout > 0) {
                usleep(500000);
                $timeout--;
            }

            if ($this->processes[$name]->isRunning()) {
                $this->processes[$name]->signal(SIGKILL);
            }
        }

        // Create and start a new process
        $this->processes[$name] = match ($name) {
            'octane' => $this->createOctaneProcess(),
            'queue' => $this->createQueueProcess(),
            'scheduler' => $this->createSchedulerProcess(),
            default => throw new \InvalidArgumentException("Unknown process: $name")
        };

        $this->processes[$name]->start();
        $this->info("[$name] Process restarted successfully");
    }

    private function stopAllProcesses(): void
    {
        $this->info('Stopping all processes...');

        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $this->info("Stopping $name...");
                $process->signal(SIGTERM);

                // Wait for a graceful shutdown
                $timeout = 10;
                while ($process->isRunning() && $timeout > 0) {
                    usleep(500000);
                    $timeout--;
                }

                // Force kill if needed
                if ($process->isRunning()) {
                    $process->signal(SIGKILL);
                    $this->warn("$name was force killed");
                } else {
                    $this->info("$name stopped gracefully");
                }
            }
        }

        // Clean up file watcher
        if ($this->inotifyResource) {
            foreach ($this->watchDescriptors as $wd => $path) {
                inotify_rm_watch($this->inotifyResource, $wd);
            }
            fclose($this->inotifyResource);
        }

        // Clean up a scheduler script
        $schedulerScript = storage_path('app/scheduler.sh');
        if (file_exists($schedulerScript)) {
            unlink($schedulerScript);
        }

        $this->info('All processes stopped successfully');
    }

    private function stopServer(): int
    {
        $this->info('Stopping development server...');

        // Kill any running processes by looking for our specific commands
        $processes = [
            'octane:start' => 'Octane server',
            'queue:work'   => 'Queue worker',
            'schedule:run' => 'Scheduler',
        ];

        foreach ($processes as $command => $description) {
            $pids = shell_exec("pgrep -f '$command' 2>/dev/null");
            if ($pids) {
                $pidArray = array_filter(explode("\n", trim($pids)));
                foreach ($pidArray as $pid) {
                    if (is_numeric($pid)) {
                        posix_kill((int)$pid, SIGTERM);
                        $this->info("Stopped $description (PID: $pid)");
                    }
                }
            }
        }

        // Clean up scheduler script
        $schedulerScript = storage_path('app/scheduler.sh');
        if (file_exists($schedulerScript)) {
            unlink($schedulerScript);
        }

        $this->info('Development server stopped');
        return 0;
    }

    private function handleWithoutInotify(): int
    {
        $this->info('Starting development server without file watching...');
        $this->info('Press Ctrl+C to stop all processes');
        $this->line('');

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'pcntlSignalHandler']);
            pcntl_signal(SIGINT, [$this, 'pcntlSignalHandler']);
            pcntl_async_signals(true);
        }

        $this->startAllProcesses();

        // Simple monitoring without file watching
        while ($this->running) {
            $this->monitorProcesses();

            usleep(500000); // 0.5 seconds (longer interval without file watching)
        }

        $this->stopAllProcesses();
        return 0;
    }
}