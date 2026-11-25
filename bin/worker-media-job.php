#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Kernel;
use App\QoL\Infrastructure\Worker\WorkerQoLLifecycle;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Worker\NativeExecutionClient;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Shared\Infrastructure\Worker\OutOfMemoryKillOrder;
use App\Transcode\Application\DTO\MediaOperation;
use App\Transcode\Application\Exception\MediaEncodingProhibitedException;
use App\Transcode\Application\Port\AudioRenditionRuntimePort;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeLoopStarterInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore;
use App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor;
use App\Transcode\Infrastructure\Worker\DoctrineMediaWorkerAuthority;
use App\Transcode\Infrastructure\Worker\MediaWorkerEnvironment;
use Baander\Core\EnvironmentVariable;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$diagnosticReserve = str_repeat(' ', 65536);
register_shutdown_function(static function () use (&$diagnosticReserve): void {
    $diagnosticReserve = null;
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        return;
    }
    $diagnostic = @fopen('php://fd/3', 'w');
    if (is_resource($diagnostic)) {
        fwrite($diagnostic, json_encode([
            'event' => 'worker_media_fatal',
            'errorType' => $error['type'],
            'sourceFile' => basename($error['file']),
            'sourceLine' => $error['line'],
            'memoryExhausted' => str_starts_with($error['message'], 'Allowed memory size of '),
        ], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fclose($diagnostic);
    }
});
$kernel = null;
$exitCode = 0;
$stopping = false;
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $profile = DeploymentProfile::fromEnvironment();
    $executionPolicy = new MediaExecutionPolicy($profile);
    $arguments = $_SERVER['argv'] ?? [];
    if (PHP_SAPI !== 'cli' || count($arguments) !== 3 || !in_array($arguments[1], ['video', 'audio'], true)) {
        throw new RuntimeException('Media job requires private manager admission.');
    }
    $identity = MediaWorkerEnvironment::identity();
    $kind = $arguments[1];
    $key = $arguments[2];
    if ($kind === 'video') {
        $jobId = Uuid::fromString($key);
    } elseif (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
        throw new RuntimeException('Media job requires a canonical runtime key.');
    }
    ini_set('memory_limit', $profile->phpHeapMiB() . 'M');
    // Host memory pressure kills media children before the control unit's other processes.
    OutOfMemoryKillOrder::raiseToMediaWork();
    pcntl_async_signals(true);
    $stop = static function () use (&$stopping): void { $stopping = true; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
    $environment = getenv(EnvironmentVariable::APP_ENV);
    $debug = getenv(EnvironmentVariable::APP_DEBUG);
    $controlDatabaseUrl = getenv(EnvironmentVariable::DATABASE_CONTROL_URL);
    if (!is_string($environment) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $environment) !== 1
        || !in_array($debug, ['0', '1'], true) || !is_string($controlDatabaseUrl) || $controlDatabaseUrl === ''
    ) {
        throw new RuntimeException('Media job requires inherited kernel configuration.');
    }
    swoole_async_set(['aio_core_worker_num' => 1, 'aio_worker_num' => 1]);
    ProcessExecutor::requireNativeExecution();
    $clientId = getenv(EnvironmentVariable::BAANDER_NATIVE_CLIENT_ID);
    $clientGeneration = getenv(EnvironmentVariable::BAANDER_NATIVE_CLIENT_GENERATION);
    $generation = is_string($clientGeneration) ? filter_var($clientGeneration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if (!is_string($clientId) || !in_array($clientId, $profile->mediaClientIds($kind), true) || $generation === false
        || !is_string($clientGeneration) || preg_match('/\A[1-9][0-9]*\z/D', $clientGeneration) !== 1
    ) {
        throw new RuntimeException('Media job requires a native client launch fence.');
    }
    $native = NativeExecutionClient::fromEnvironment($clientId, $generation);
    if (($native->handshake()['ready'] ?? null) !== true) {
        throw new RuntimeException('Native execution peer is not ready.');
    }
    ProcessExecutor::configureNativeExecution($native);
    $authority = DoctrineMediaWorkerAuthority::fromDsn($controlDatabaseUrl);
    $authority->assertActive();
    $kernel = new Kernel($environment, $debug === '1');
    $kernel->boot();
    $container = $kernel->getContainer();
    $container->set(NativeExecutionClientInterface::class, $native);
    $authorityChecking = false;
    $stopRequested = static function () use (&$stopping, &$authorityChecking, $authority): bool {
        if ($stopping) {
            return true;
        }
        // The control timer may interrupt a suspended check from the main coroutine.
        // Only the in-flight check uses this dedicated connection; its result is shared.
        if ($authorityChecking) {
            return false;
        }
        $authorityChecking = true;
        try {
            $authority->assertActive();
            return false;
        } catch (Throwable) {
            $stopping = true;
            return true;
        } finally {
            $authorityChecking = false;
        }
    };
    if (!$stopRequested()) {
        $authority->assertActive();
        if ($kind === 'video') {
            $runtime = $container->get('worker.media.video_runtime');
            if (!$runtime instanceof TranscodeExecutionOwnershipPort) {
                throw new RuntimeException('Media video runtime is not configured.');
            }
            $claim = $runtime->claim($jobId, $identity);
            $copy = $claim?->runtime->desired->presentationSelection?->mode === 'copy';
            if ($claim !== null) {
                try {
                    $executionPolicy->assertAllowed($copy ? MediaOperation::VideoStreamCopy : MediaOperation::VideoEncode);
                } catch (MediaEncodingProhibitedException $refusal) {
                    $runtime->observe($claim, 'failed', error: $refusal->getMessage());
                    $runtime->release($claim);
                    $claim = null;
                }
            }
            if ($claim !== null) {
                if (!$copy) {
                    $snapshots = $container->get('worker.media.encoder_snapshot_store');
                    if (!$snapshots instanceof WorkerEncoderCapabilitiesSnapshotStore) {
                        throw new RuntimeException('Media encoder snapshot store is not configured.');
                    }
                    $snapshot = $snapshots->read();
                    if ($snapshot === null || $snapshot->workerIdentity !== $identity->bootId . ':' . $identity->launchGeneration) {
                        throw new RuntimeException('Media job requires current worker encoder capabilities.');
                    }
                    $container->set('worker.media.encoder_profile', $snapshot->profile);
                }
                $executor = $container->get('transcode.loop_starter');
                if (!$executor instanceof TranscodeLoopStarterInterface) {
                    throw new RuntimeException('Media video executor is not configured.');
                }
                $qol = $container->get('worker.media.qol_lifecycle');
                if (!$qol instanceof WorkerQoLLifecycle) {
                    throw new RuntimeException('Media worker QoL lifecycle is not configured.');
                }
                \Swoole\Coroutine\run(static function () use ($executor, $claim, $stopRequested, $qol, $copy): void {
                    if ($copy) {
                        $executor->run($claim, $stopRequested);
                        return;
                    }
                    $qol->start();
                    try {
                        $executor->run($claim, $stopRequested);
                    } finally {
                        $qol->stop();
                    }
                });
            }
        } else {
            $runtime = $container->get('worker.media.audio_runtime');
            if (!$runtime instanceof AudioRenditionRuntimePort) {
                throw new RuntimeException('Media audio runtime is not configured.');
            }
            $claim = $runtime->claim($key, $identity);
            if ($claim !== null) {
                $executor = $container->get('worker.media.audio_executor');
                if (!$executor instanceof AudioRenditionExecutor) {
                    throw new RuntimeException('Media audio executor is not configured.');
                }
                $executor->run($claim, $stopRequested);
            }
        }
    }
} catch (Throwable $error) {
    $diagnostic = @fopen('php://fd/3', 'w');
    if (is_resource($diagnostic)) {
        fwrite($diagnostic, json_encode([
            'event' => 'worker_media_failure',
            'exceptionClass' => $error::class,
            'sourceFile' => basename($error->getFile()),
            'sourceLine' => $error->getLine(),
        ], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fclose($diagnostic);
    }
    fwrite(STDERR, "Media execution failed; deployment reconciliation is required.\n");
    $exitCode = 1;
} finally {
    try {
        $kernel?->shutdown();
    } catch (Throwable) {
        fwrite(STDERR, "Media execution kernel shutdown failed.\n");
        $exitCode = 1;
    }
}
exit($exitCode);
