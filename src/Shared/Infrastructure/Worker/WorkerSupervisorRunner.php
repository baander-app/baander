<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use App\Shared\Application\Port\WorkerSupervisorRunnerInterface;

/** Initial fixed-role PID-1 runtime; child replacement requires external deployment recovery. */
final readonly class WorkerSupervisorRunner implements WorkerSupervisorRunnerInterface
{
    public function __construct(private string $projectDirectory, private string $databaseUrl)
    {
        if (!str_starts_with($projectDirectory, '/') || realpath($projectDirectory) !== $projectDirectory
            || !is_file($projectDirectory . '/bin/console') || !is_file($projectDirectory . '/bin/worker-lease-agent.php')
            || $databaseUrl === '' || str_contains($databaseUrl, "\0")
        ) {
            throw new \InvalidArgumentException('Worker runner requires a trusted canonical project with console/lease entrypoints and a database URL.');
        }
    }

    public function run(WorkerRuntimeConfiguration $configuration): int
    {
        if (!function_exists('posix_getpid') || posix_getpid() !== 1 || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler') || !function_exists('pcntl_async_signals') || !function_exists('pcntl_exec')
        ) {
            throw new \RuntimeException('Worker supervisor must run as deployment PID 1 with pcntl and posix support.');
        }
        if (!is_dir($configuration->lockDirectory) && !mkdir($configuration->lockDirectory, 0700)) {
            throw new \RuntimeException('Cannot create private worker lock directory.');
        }
        $lock = LocalSupervisorLock::acquire($configuration->lockDirectory, 'worker-' . substr(hash('sha256', $configuration->namespace), 0, 32));
        $previousAsync = pcntl_async_signals(true);
        $previousTerm = pcntl_signal_get_handler(SIGTERM);
        $previousInt = pcntl_signal_get_handler(SIGINT);
        $stopRequested = false;
        $stop = static function () use (&$stopRequested): void { $stopRequested = true; };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        try {
            $environment = $this->environment();
            $environment['DATABASE_URL'] = $this->databaseUrl;
            $environment['BAANDER_WORKER_NAMESPACE'] = $configuration->namespace;
            $environment['BAANDER_WORKER_BOOT_ID'] = $configuration->bootId;
            $launchAttempts = 0;
            $definitions = [
                new WorkerDefinition('consumer', [PHP_BINARY, '-d', 'memory_limit=256M', $this->projectDirectory . '/bin/console',
                    'messenger:consume', 'async', '--memory-limit=256M', '--keepalive=30', '--no-interaction'],
                    $this->projectDirectory, $configuration->consumerReservationBytes, 30.0),
                new WorkerDefinition('relay', [PHP_BINARY, '-d', 'memory_limit=256M', $this->projectDirectory . '/bin/console',
                    'app:outbox:consume', '--time-limit=86400', '--no-interaction'],
                    $this->projectDirectory, $configuration->relayReservationBytes, 30.0),
            ];
            $runtime = new LeasedWorkerRuntime($definitions, 3, $configuration->memoryLimitBytes, $configuration->managementReservationBytes,
                new LeaseAuthority($configuration->namespace, $configuration->bootId, 30, 10, 1),
                static function (WorkerDefinition $definition, WorkerLaunchIdentity $identity) use ($environment, &$stopRequested, &$launchAttempts): WorkerChildProcess {
                    if ($stopRequested) {
                        throw new \RuntimeException('Worker admission stopped.');
                    }
                    $childEnvironment = $environment;
                    $childEnvironment['BAANDER_WORKER_ID'] = $identity->workerId;
                    $childEnvironment['BAANDER_WORKER_GENERATION'] = (string) $identity->generation;
                    $childEnvironment['MESSENGER_CONSUMER_NAME'] = 'worker-' . substr(hash('sha256', $identity->deploymentId), 0, 16) . '-' . $identity->supervisorBootId . '-' . $identity->workerId . '-' . $identity->generation;
                    ++$launchAttempts;
                    return WorkerChildProcess::start($definition->argv, $definition->directory, STDOUT, STDERR, $childEnvironment);
                },
                fn (array $request, float $now): LeaseAgentProcess => LeaseAgentProcess::start($request, $this->projectDirectory, $now, 2.0, $environment));
            $drainStarted = null;
            $exitCode = 0;
            while (true) {
                if ($stopRequested && $drainStarted === null) {
                    $drainStarted = hrtime(true) / 1e9;
                    try {
                        $runtime->requestDrain();
                    } catch (\Throwable) {
                        $exitCode = 1;
                    }
                }
                try {
                    $runtime->tick();
                } catch (\Throwable) {
                    $exitCode = 1;
                    $drainStarted ??= hrtime(true) / 1e9;
                }
                if ($drainStarted === null) {
                    $failed = $runtime->failureCode() !== null;
                    foreach ($runtime->snapshot() as $worker) {
                        if (in_array($worker['state'], ['awaiting_containment', 'backoff', 'exhausted'], true)
                            || $worker['exitCode'] !== null || $worker['terminationSignal'] !== null || $worker['errorCode'] !== null
                        ) {
                            $failed = true;
                        }
                    }
                    if ($failed) {
                        $exitCode = 1;
                        $drainStarted = hrtime(true) / 1e9;
                        try {
                            $runtime->requestDrain();
                        } catch (\Throwable) {
                            // Continue bounded direct-child reaping; ownership stays reserved.
                        }
                    }
                }
                if ($drainStarted !== null) {
                    if ($runtime->areDirectChildrenReaped()) {
                        $this->stoppedSummary($launchAttempts, $exitCode);
                        return $exitCode;
                    }
                    if (hrtime(true) / 1e9 >= $drainStarted + 35.0) {
                        // Replacing this PHP process avoids potentially blocking proc_close
                        // destructors. Fresh PID 1 exits and its namespace owns final teardown.
                        pcntl_exec(PHP_BINARY, ['-r', 'exit(1);']);
                        throw new \RuntimeException('Cannot exit the deployment boundary after its drain deadline.');
                    }
                }
                usleep(10_000);
            }
        } finally {
            pcntl_signal(SIGTERM, $previousTerm);
            pcntl_signal(SIGINT, $previousInt);
            pcntl_async_signals($previousAsync);
            $lock->release();
        }
    }

    /** Best-effort bounded diagnostics after direct reaping; never a readiness or containment receipt. */
    private function stoppedSummary(int $launchAttempts, int $exitCode): void
    {
        $blocked = stream_get_meta_data(STDOUT)['blocked'];
        try {
            if (stream_set_blocking(STDOUT, false)) {
                fwrite(STDOUT, json_encode(['event' => 'worker_supervisor_stopped', 'launchAttempts' => $launchAttempts, 'exitCode' => $exitCode], JSON_THROW_ON_ERROR) . "\n");
            }
        } catch (\Throwable) {
            // Diagnostic failure cannot reopen admission or delay the deployment exit.
        } finally {
            stream_set_blocking(STDOUT, $blocked);
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($name) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1 && is_scalar($value)) {
                    $text = (string) $value;
                    if (!str_contains($text, "\0")) {
                        $environment[$name] = $text;
                    }
                }
            }
        }
        // Values resolved by the parent are external configuration in a fresh
        // child. Parent Dotenv provenance would incorrectly permit overriding
        // explicit launch identity when the child's kernel loads its own files.
        unset($environment['SYMFONY_DOTENV_VARS']);
        return $environment;
    }
}
