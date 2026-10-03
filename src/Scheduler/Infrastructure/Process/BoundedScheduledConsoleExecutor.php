<?php

declare(strict_types=1);

namespace App\Scheduler\Infrastructure\Process;

use App\Scheduler\Application\Exception\ScheduledConsoleCompletionUnknown;
use App\Scheduler\Application\Port\ScheduledConsoleExecutorInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** CLI-only direct child. Deadlines cannot prove descendant containment or interrupt kernel-stalled processes. */
#[Exclude]
final class BoundedScheduledConsoleExecutor implements ScheduledConsoleExecutorInterface
{
    public function __construct(
        private readonly string $projectDirectory,
        private readonly float $timeoutSeconds = 300.0,
        private readonly int $outputLimitBytes = 10000,
        private readonly int $memoryLimitMiB = 128,
        private readonly float $terminationGraceSeconds = 1.0,
        private readonly int $reservationBytes = 0,
    ) {
        if (!str_starts_with($projectDirectory, '/') || str_contains($projectDirectory, "\0") || !is_dir($projectDirectory)
            || !is_file($projectDirectory . '/bin/console') || !is_readable($projectDirectory . '/bin/console')) {
            throw new \InvalidArgumentException('Scheduled console requires an absolute project directory with readable bin/console.');
        }
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $timeoutSeconds >= 3600
            || $outputLimitBytes < 1 || $outputLimitBytes > 1048576 || $memoryLimitMiB < 16 || $memoryLimitMiB > 256
            || !is_finite($terminationGraceSeconds) || $terminationGraceSeconds < 0 || $terminationGraceSeconds > 5) {
            throw new \InvalidArgumentException('Scheduled console process settings exceed bounded limits.');
        }
        if ($reservationBytes < 0 || $reservationBytes > 1024 * 1024 * 1024 * 1024
            || ($reservationBytes !== 0 && $reservationBytes < ($memoryLimitMiB + 64) * 1024 * 1024)) {
            throw new \InvalidArgumentException('Scheduled console reservation must cover its PHP heap plus at least 64 MiB of native headroom.');
        }
    }

    /** Supervisor declaration only: actual memory/process containment remains the deployment's responsibility. */
    public static function fromWorkerEnvironment(string $projectDirectory): self
    {
        $raw = getenv('BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES');
        if ($raw === false || $raw === '0') {
            return new self($projectDirectory);
        }
        $bytes = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1024 * 1024 * 1024 * 1024]]);
        if (preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || $bytes === false || getenv('BAANDER_WORKER_ID') !== 'consumer') {
            throw new \InvalidArgumentException('Scheduled console requires an explicit consumer subprocess reservation.');
        }
        return new self($projectDirectory, reservationBytes: $bytes);
    }

    /** @param array<int|string, mixed> $parameters */
    public function execute(string $command, array $parameters): string
    {
        if ($this->reservationBytes === 0) {
            throw new \RuntimeException('Scheduled console execution is disabled without a subprocess reservation.');
        }
        if (PHP_SAPI !== 'cli' || (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0)) {
            throw new \LogicException('Scheduled console executor requires CLI outside an active coroutine.');
        }
        $argv = $this->arguments($command, $parameters);
        $environment = getenv();
        $environment['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'] = '0';
        // Preserve explicit launch configuration when the child reloads Dotenv.
        unset($environment['SYMFONY_DOTENV_VARS']);
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->projectDirectory, $environment);
        if (!is_resource($process)) {
            throw new \RuntimeException('Scheduled console child could not be started.');
        }
        $stdout = '';
        $received = 0;
        $exitCode = null;
        $signaled = false;
        $reaped = false;
        $deadline = hrtime(true) / 1e9 + $this->timeoutSeconds;
        try {
            fclose($pipes[0]);
            if (!stream_set_blocking($pipes[1], false) || !stream_set_blocking($pipes[2], false)) {
                // Cleanup must not read a pipe whose nonblocking setup failed.
                fclose($pipes[1]);
                fclose($pipes[2]);
                throw new ScheduledConsoleCompletionUnknown('Scheduled console completion unknown: nonblocking output setup failed.');
            }
            while (true) {
                if (hrtime(true) / 1e9 >= $deadline) {
                    throw new ScheduledConsoleCompletionUnknown('Scheduled console completion unknown: deadline exceeded; reconcile descendants before further execution.');
                }
                // At most one bounded chunk per stream per iteration: a noisy stream cannot starve its peer or the clock.
                foreach ([1, 2] as $descriptor) {
                    $chunk = fread($pipes[$descriptor], 4096);
                    if ($chunk === false) {
                        throw new ScheduledConsoleCompletionUnknown('Scheduled console completion unknown: output could not be read.');
                    }
                    $received += strlen($chunk);
                    if ($received > $this->outputLimitBytes) {
                        throw new ScheduledConsoleCompletionUnknown('Scheduled console completion unknown: combined output limit exceeded; reconcile descendants before further execution.');
                    }
                    if ($descriptor === 1) {
                        $stdout .= $chunk;
                    }
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $signaled = $signaled || $status['signaled'];
                    $exitCode ??= $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                    if (feof($pipes[1]) && feof($pipes[2])) {
                        break;
                    }
                }
                usleep(1000);
            }
            foreach ([1, 2] as $descriptor) {
                fclose($pipes[$descriptor]);
            }
            $closedCode = proc_close($process);
            $reaped = true;
            $exitCode ??= $closedCode >= 0 ? $closedCode : null;
            if ($signaled || $exitCode === null) {
                throw new ScheduledConsoleCompletionUnknown('Scheduled console completion unknown: child terminated by signal or without a confirmed exit status.');
            }
            if ($exitCode !== 0) {
                throw new \RuntimeException('Scheduled console child exited unsuccessfully.');
            }
            if (preg_match('//u', $stdout) !== 1) {
                throw new \UnexpectedValueException('Scheduled console child returned invalid UTF-8 output.');
            }
            return $stdout;
        } finally {
            if (!$reaped) {
                $this->stopDirectChild($process, $pipes);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $parameters
     * @return list<string>
     */
    private function arguments(string $command, array $parameters): array
    {
        if (strlen($command) > 512 || preg_match('/\A[a-z][a-z0-9_-]*(?::[a-z][a-z0-9_-]*)*\z/D', $command) !== 1) {
            throw new \InvalidArgumentException('Scheduled console command must be a canonical command name.');
        }
        $argv = [PHP_BINARY, '-d', 'memory_limit=' . $this->memoryLimitMiB . 'M', $this->projectDirectory . '/bin/console', $command, '--no-interaction', '--no-ansi'];
        $positionals = [];
        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $positionals[] = $this->scalarArgument($value);
            } else {
                $name = str_starts_with($key, '--') ? substr($key, 2) : $key;
                if (strlen($name) > 128 || preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', $name) !== 1
                    || in_array($name, ['no-interaction', 'no-ansi'], true)) {
                    throw new \InvalidArgumentException('Scheduled console option name is invalid or reserved.');
                }
                if ($value === true) {
                    $argv[] = '--' . $name;
                } elseif ($value !== false) {
                    $argv[] = '--' . $name . '=' . $this->scalarArgument($value);
                }
            }
            $this->checkArgumentBudget([...$argv, ...$positionals]);
        }
        if ($positionals !== []) {
            $argv[] = '--';
            array_push($argv, ...$positionals);
        }
        $this->checkArgumentBudget($argv);
        return $argv;
    }

    private function scalarArgument(mixed $value): string
    {
        if (is_string($value)) {
            if (str_contains($value, "\0") || strlen($value) > 16384) {
                throw new \InvalidArgumentException('Scheduled console argument exceeds its byte limit or contains NUL.');
            }
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        throw new \InvalidArgumentException('Scheduled console arguments must be strings, integers or finite floats; booleans are named flags only.');
    }

    /** @param list<string> $arguments */
    private function checkArgumentBudget(array $arguments): void
    {
        $bytes = 0;
        foreach ($arguments as $argument) {
            $bytes += strlen($argument) + 1;
            if ($bytes > 16384) {
                throw new \InvalidArgumentException('Scheduled console argument vector exceeds 16 KiB.');
            }
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * Normal signalable direct children are reaped; a kernel-stalled child remains uncertain.
     */
    private function stopDirectChild(mixed $process, array $pipes): void
    {
        if ($this->isRunning($process)) {
            proc_terminate($process, 15);
            $killAt = hrtime(true) / 1e9 + $this->terminationGraceSeconds;
            while ($this->isRunning($process) && hrtime(true) / 1e9 < $killAt) {
                $this->discardOutput($pipes);
                usleep(1000);
            }
            if ($this->isRunning($process)) {
                proc_terminate($process, 9);
            }
        }
        $reapDeadline = hrtime(true) / 1e9 + 1.0;
        while ($this->isRunning($process) && hrtime(true) / 1e9 < $reapDeadline) {
            $this->discardOutput($pipes);
            usleep(1000);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (!$this->isRunning($process)) {
            proc_close($process);
        }
        // Avoid explicit blocking proc_close on a still-running uninterruptible child.
        // PHP resource teardown may nevertheless wait: no bounded OS shutdown or descendant containment is promised.
    }

    /**
     * OS process state changes independently between calls.
     * @phpstan-impure
     * @param resource $process
     */
    private function isRunning(mixed $process): bool
    {
        return proc_get_status($process)['running'];
    }

    /** @param array<int, resource> $pipes */
    private function discardOutput(array $pipes): void
    {
        foreach ([1, 2] as $descriptor) {
            if (is_resource($pipes[$descriptor])) {
                fread($pipes[$descriptor], 4096);
            }
        }
    }
}
