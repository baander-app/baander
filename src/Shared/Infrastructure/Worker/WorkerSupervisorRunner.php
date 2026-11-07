<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\WorkerRuntimeConfiguration;
use App\Shared\Application\Port\WorkerSupervisorRunnerInterface;
use App\Shared\Infrastructure\Doctrine\DatabaseTimeZoneGuard;
use App\Shared\Infrastructure\Messaging\AsyncWorkloadCatalog;
use App\Shared\Infrastructure\Messaging\WorkerTransportConfiguration;
use App\Shared\Infrastructure\Worker\Autoscaling\AdmissionLedger;
use App\Shared\Infrastructure\Worker\Autoscaling\CgroupResourceSampler;
use App\Shared\Infrastructure\Worker\Autoscaling\ResourceChannel;

/** Resident-role PID-1 runtime; child replacement requires external deployment recovery. */
final readonly class WorkerSupervisorRunner implements WorkerSupervisorRunnerInterface
{
    public function __construct(private string $projectDirectory, private string $databaseUrl, private string $controlDatabaseUrl, private AsyncWorkloadCatalog $workloads)
    {
        if (!str_starts_with($projectDirectory, '/') || realpath($projectDirectory) !== $projectDirectory
            || !is_file($projectDirectory . '/bin/console') || !is_file($projectDirectory . '/bin/worker-lease-agent.php')
            || !is_file($projectDirectory . '/bin/worker-autoscaler.php')
            || !is_file($projectDirectory . '/bin/worker-native.php') || !is_file($projectDirectory . '/bin/worker-scheduler.php') || !is_file($projectDirectory . '/bin/worker-media.php') || !is_file($projectDirectory . '/bin/worker-media-job.php')
            || $databaseUrl === '' || str_contains($databaseUrl, "\0")
            || $controlDatabaseUrl === '' || str_contains($controlDatabaseUrl, "\0")
        ) {
            throw new \InvalidArgumentException('Worker runner requires a trusted canonical project with console/lease/scheduler/media entrypoints and database and control database URLs.');
        }
    }

    public function run(WorkerRuntimeConfiguration $configuration): int
    {
        if (!function_exists('posix_getpid') || posix_getpid() !== 1 || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler') || !function_exists('pcntl_async_signals') || !function_exists('pcntl_exec')
        ) {
            throw new \RuntimeException('Worker supervisor must run as deployment PID 1 with pcntl and posix support.');
        }
        $environment = $this->environment();
        // Children connect through both URLs (the control URL logs in as its own role), so
        // neither may default to another zone. Once per unit start, before any child exists.
        foreach (array_unique([$this->databaseUrl, $this->controlDatabaseUrl]) as $databaseUrl) {
            DatabaseTimeZoneGuard::assertUtcDefaultAt($databaseUrl);
        }
        if (!is_dir($configuration->lockDirectory) && !mkdir($configuration->lockDirectory, 0700)) {
            throw new \RuntimeException('Cannot create private worker lock directory.');
        }
        $lock = LocalSupervisorLock::acquire($configuration->lockDirectory, 'worker-' . $configuration->unit . '-' . substr(hash('sha256', $configuration->namespace), 0, 32));
        $previousAsync = pcntl_async_signals(true);
        $previousTerm = pcntl_signal_get_handler(SIGTERM);
        $previousInt = pcntl_signal_get_handler(SIGINT);
        $stopRequested = false;
        $stop = static function () use (&$stopRequested): void { $stopRequested = true; };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        try {
            $environment['DATABASE_URL'] = $this->databaseUrl;
            $environment['DATABASE_CONTROL_URL'] = $this->controlDatabaseUrl;
            $environment['BAANDER_WORKER_NAMESPACE'] = $configuration->namespace;
            $environment['BAANDER_WORKER_BOOT_ID'] = $configuration->bootId;
            $startedAt = hrtime(true) / 1e9;
            $peer = new WorkerPeerHandshake($configuration->ipcDirectory, $configuration->namespace, $configuration->bootId, $configuration->unit, $startedAt, $configuration->deploymentProfile,
                static fn (): float => hrtime(true) / 1e9);
            $environment['BAANDER_NATIVE_SOCKET'] = $peer->socketPath();
            $environment['BAANDER_WORKER_UNIT'] = $configuration->unit;
            $environment['BAANDER_DEPLOYMENT_PROFILE'] = $configuration->deploymentProfile->toJson();
            $environment['BAANDER_WORKER_AUTOSCALING_PROFILE'] = $configuration->autoscaling->toJson();
            $environment['BAANDER_WORKER_IPC_DIRECTORY'] = $configuration->ipcDirectory;
            $native = null;
            $nativeReady = false;
            $epoch = null;
            $ledger = null;
            $resourceChannel = null;
            $resourceSampler = $configuration->unit === 'execution' ? new CgroupResourceSampler() : null;
            $nextResourceSample = 0.0;
            $launchAttempts = 0;
            $definitions = $this->definitions($configuration);
            $authority = new LeaseAuthority($configuration->namespace, $configuration->bootId, $configuration->unit === 'control' ? 30 : 3, $configuration->unit === 'control' ? 10 : 2, 0.1, $configuration->unit === 'execution');
            $runtime = new LeasedWorkerRuntime($definitions, $configuration->childProcessCeiling,
                $authority,
                function (WorkerDefinition $definition, WorkerLaunchIdentity $identity) use ($authority, $environment, $configuration, &$stopRequested, &$launchAttempts): WorkerChildProcess {
                    if ($stopRequested) {
                        throw new \RuntimeException('Worker admission stopped.');
                    }
                    $childEnvironment = $this->childEnvironment($environment, $definition, $configuration, $identity, $authority, hrtime(true) / 1e9);
                    ++$launchAttempts;
                    return WorkerChildProcess::start($definition->argv, $definition->directory, STDOUT, STDERR, $childEnvironment);
                },
                fn (array $request, float $now): LeaseAgentProcess => LeaseAgentProcess::start($request, $this->projectDirectory, $now, 1.5, $environment),
                admissionGate: function () use ($authority, $configuration, $environment, $peer, $resourceSampler, &$native, &$nativeReady, &$epoch, &$ledger, &$resourceChannel, &$nextResourceSample): bool {
                    $now = hrtime(true) / 1e9;
                    $lease = $authority->lease($now);
                    if ($lease === null) {
                        return false;
                    }
                    $epoch = $lease->epoch;
                    if ($configuration->unit === 'control' && $ledger === null) {
                        // Create once before execution can observe ready authority.
                        $ledger = new AdmissionLedger($configuration->ipcDirectory, $lease, $configuration->autoscaling, $configuration->deploymentProfile);
                        $ledger->initialize($now);
                    }
                    if ($resourceSampler !== null && $now >= $nextResourceSample) {
                        $resourceChannel ??= new ResourceChannel($configuration->ipcDirectory, $lease);
                        $resourceChannel->publish($resourceSampler->sample($now));
                        $nextResourceSample = $now + $configuration->autoscaling->sampleIntervalMs / 1000;
                    }
                    if ($configuration->unit === 'execution') {
                        if ($native === null) {
                            $nativeEnvironment = $environment;
                            $nativeEnvironment['BAANDER_WORKER_LEASE_EPOCH'] = (string) $epoch;
                            $nativeEnvironment['BAANDER_WORKER_ID'] = 'native';
                            $nativeEnvironment['BAANDER_WORKER_GENERATION'] = '1';
                            $native = WorkerChildProcess::start([PHP_BINARY, '-d', 'memory_limit=' . $configuration->deploymentProfile->nativeManagerHeapMiB() . 'M', $this->projectDirectory . '/bin/worker-native.php'], $this->projectDirectory, STDOUT, STDERR, $nativeEnvironment);
                        }
                        if (!$native->poll($now)) {
                            throw new \RuntimeException('Native execution manager exited.');
                        }
                        if (!$nativeReady && file_exists($peer->socketPath())) {
                            $client = new NativeExecutionClient($peer->socketPath(), $configuration->namespace, $configuration->bootId, $epoch, 'supervisor', 1, $configuration->deploymentProfile);
                            $reply = $client->handshake();
                            $nativeReady = ($reply['ready'] ?? false) === true;
                        }
                    }
                    $peer->publish($epoch, $configuration->unit === 'control' || $nativeReady, false, $now);
                    return ($configuration->unit === 'control' || $nativeReady) && $peer->ready($epoch, $now);
                });
            $drainStarted = null;
            $exitCode = 0;
            /** @var array<string, mixed>|null $cause */
            $cause = null;
            while (true) {
                if ($drainStarted === null && hrtime(true) / 1e9 >= $startedAt + 30.0 && $launchAttempts === 0) {
                    $stopRequested = true;
                    $exitCode = 1;
                    $cause ??= ['reason' => 'no_launch'];
                }
                if ($stopRequested && $drainStarted === null) {
                    $drainStarted = hrtime(true) / 1e9;
                    try {
                        $runtime->requestDrain();
                    } catch (\Throwable) {
                        $exitCode = 1;
                    }
                }
                try {
                    // Execution cannot observe before control has committed and published authority.
                    if ($drainStarted !== null || $configuration->unit !== 'execution' || $epoch !== null || $peer->ready(null, hrtime(true) / 1e9)) {
                        $runtime->tick();
                    }
                } catch (\Throwable $error) {
                    $exitCode = 1;
                    // Source location only: messages may carry connection details.
                    $cause ??= ['reason' => 'management_failed', 'error' => $error::class, 'source' => basename($error->getFile()) . ':' . $error->getLine()];
                    $drainStarted ??= hrtime(true) / 1e9;
                    try {
                        $runtime->requestDrain();
                    } catch (\Throwable) {
                        // Retain the bounded outer teardown when management fails.
                    }
                }
                if ($drainStarted === null) {
                    $failed = $runtime->failureCode() !== null;
                    $failedWorkers = [];
                    foreach ($runtime->snapshot() as $id => $worker) {
                        if (in_array($worker['state'], ['awaiting_containment', 'backoff', 'exhausted'], true)
                            || $worker['exitCode'] !== null || $worker['terminationSignal'] !== null || $worker['errorCode'] !== null
                        ) {
                            $failed = true;
                            $failedWorkers[] = ['id' => $id, 'state' => $worker['state'], 'exitCode' => $worker['exitCode'],
                                'terminationSignal' => $worker['terminationSignal'], 'errorCode' => $worker['errorCode']];
                        }
                    }
                    if ($failed) {
                        $exitCode = 1;
                        $cause ??= ['reason' => 'role_failed', 'failure' => $runtime->failureCode(), 'workers' => $failedWorkers];
                        $drainStarted = hrtime(true) / 1e9;
                        try {
                            $runtime->requestDrain();
                        } catch (\Throwable) {
                            // Continue bounded direct-child reaping; ownership stays reserved.
                        }
                    }
                }
                if ($drainStarted !== null) {
                    try {
                        $peer->publish($epoch ?? 1, false, true, hrtime(true) / 1e9);
                        $native?->requestStop(hrtime(true) / 1e9, 30.0);
                    } catch (\Throwable) {
                        $exitCode = 1;
                    }
                    $nativeReaped = $native === null || !$native->poll(hrtime(true) / 1e9);
                    if ($runtime->areDirectChildrenReaped() && $nativeReaped) {
                        $this->stoppedSummary($launchAttempts, $exitCode, $cause ?? ($stopRequested ? ['reason' => 'stop_requested'] : null));
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
    /** @param array<string, mixed>|null $cause The first reason the unit drained, when it was not asked to stop. */
    private function stoppedSummary(int $launchAttempts, int $exitCode, ?array $cause): void
    {
        $blocked = stream_get_meta_data(STDOUT)['blocked'];
        try {
            if (stream_set_blocking(STDOUT, false)) {
                fwrite(STDOUT, json_encode(['event' => 'worker_supervisor_stopped', 'launchAttempts' => $launchAttempts, 'exitCode' => $exitCode, 'cause' => $cause], JSON_THROW_ON_ERROR) . "\n");
            }
        } catch (\Throwable) {
            // Diagnostic failure cannot reopen admission or delay the deployment exit.
        } finally {
            stream_set_blocking(STDOUT, $blocked);
        }
    }

    /** @return list<WorkerDefinition> */
    private function definitions(WorkerRuntimeConfiguration $configuration): array
    {
        if ($this->workloads->receivers() !== ['async', 'scheduler']) {
            throw new \RuntimeException('Worker units require the async and scheduler receiver catalog.');
        }
        $heap = $configuration->deploymentProfile->phpHeapMiB() . 'M';
        $helpers = WorkerRuntimeConfiguration::CONSUMER_DESCENDANTS;
        if ($configuration->unit === 'execution') {
            $roles = [];
            foreach ($configuration->autoscaling->consumerIds() as $id) {
                $roles[] = new WorkerDefinition($id, [PHP_BINARY, '-d', 'memory_limit=' . $heap, $this->projectDirectory . '/bin/console',
                    'messenger:consume', 'async', '--memory-limit=' . $heap, '--keepalive=30', '--no-interaction'],
                    $this->projectDirectory, 30.0, descendantProcessReservation: $helpers);
            }
            $roles[] = new WorkerDefinition('scheduled-consumer', [PHP_BINARY, '-d', 'memory_limit=' . $heap, $this->projectDirectory . '/bin/console',
                    'messenger:consume', 'scheduler', '--memory-limit=' . $heap, '--keepalive=30', '--no-interaction'],
                    $this->projectDirectory, 30.0, descendantProcessReservation: $helpers + ($configuration->scheduledConsoleReservationBytes > 0 ? 1 : 0));
            return $roles;
        }
        return [
            new WorkerDefinition('relay', [PHP_BINARY, '-d', 'memory_limit=' . $heap, $this->projectDirectory . '/bin/console',
                'app:outbox:consume', '--time-limit=86400', '--no-interaction'], $this->projectDirectory, 30.0),
            new WorkerDefinition('scheduler', [PHP_BINARY, '-d', 'memory_limit=' . $heap, $this->projectDirectory . '/bin/worker-scheduler.php'], $this->projectDirectory, 30.0),
            // Media children run every command through the native daemon, so each slot adds one process.
            new WorkerDefinition('media', [PHP_BINARY, '-d', 'memory_limit=' . $heap, $this->projectDirectory . '/bin/worker-media.php'], $this->projectDirectory, 30.0, descendantProcessReservation: $configuration->deploymentProfile->mediaChildSlots()),
            new WorkerDefinition('autoscaler', [PHP_BINARY, '-d', 'memory_limit=' . $heap, $this->projectDirectory . '/bin/worker-autoscaler.php'], $this->projectDirectory, 30.0),
        ];
    }

    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    private function childEnvironment(array $environment, WorkerDefinition $definition, WorkerRuntimeConfiguration $configuration, WorkerLaunchIdentity $identity, LeaseAuthority $authority, float $now): array
    {
        $lease = $authority->lease($now);
        if ($lease === null || $lease->namespace !== $identity->deploymentId || $lease->bootId !== $identity->supervisorBootId
            || $identity->deploymentId !== $configuration->namespace || $identity->supervisorBootId !== $configuration->bootId
            || $identity->workerId !== $definition->id
        ) {
            throw new \RuntimeException('Worker admission requires current committed deployment authority and matching launch identity.');
        }
        $environment['BAANDER_WORKER_NAMESPACE'] = $identity->deploymentId;
        $environment['BAANDER_WORKER_BOOT_ID'] = $identity->supervisorBootId;
        $environment['BAANDER_WORKER_ID'] = $identity->workerId;
        $environment['BAANDER_WORKER_GENERATION'] = (string) $identity->generation;
        $environment['BAANDER_WORKER_LEASE_EPOCH'] = (string) $lease->epoch;
        $environment['MESSENGER_CONSUMER_NAME'] = 'worker-' . substr(hash('sha256', $identity->deploymentId), 0, 16) . '-' . $identity->supervisorBootId . '-' . $identity->workerId . '-' . $identity->generation;
        return $this->roleEnvironment($environment, $definition, $configuration);
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        $environment = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($name) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1 && is_scalar($value)) {
                    $text = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
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
        // Only the admitted consumer may receive a nested console reservation.
        $environment['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'] = '0';
        WorkerTransportConfiguration::validate($environment, required: true);
        return $environment;
    }

    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    private function roleEnvironment(array $environment, WorkerDefinition $definition, WorkerRuntimeConfiguration $configuration): array
    {
        $environment['BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES'] = (string) ($definition->id === 'scheduled-consumer' ? $configuration->scheduledConsoleReservationBytes : 0);
        return $environment;
    }
}
