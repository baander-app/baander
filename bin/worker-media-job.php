#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$kernel = null;
$exitCode = 0;
$stopping = false;
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $arguments = $_SERVER['argv'] ?? [];
    if (PHP_SAPI !== 'cli' || count($arguments) !== 3 || !in_array($arguments[1], ['video', 'audio'], true)) {
        throw new RuntimeException('Media job requires private manager admission.');
    }
    $identity = \App\Transcode\Infrastructure\Worker\MediaWorkerEnvironment::identity();
    $kind = $arguments[1];
    $key = $arguments[2];
    if ($kind === 'video') {
        $jobId = \App\Shared\Domain\Model\Uuid::fromString($key);
    } elseif (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
        throw new RuntimeException('Media job requires a canonical runtime key.');
    }
    ini_set('memory_limit', '256M');
    pcntl_async_signals(true);
    $stop = static function () use (&$stopping): void { $stopping = true; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
    $environment = getenv('APP_ENV');
    $debug = getenv('APP_DEBUG');
    $databaseUrl = getenv('DATABASE_URL');
    if (!is_string($environment) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $environment) !== 1
        || !in_array($debug, ['0', '1'], true) || !is_string($databaseUrl) || $databaseUrl === ''
    ) {
        throw new RuntimeException('Media job requires inherited kernel configuration.');
    }
    swoole_async_set(['aio_core_worker_num' => 1, 'aio_worker_num' => 1]);
    \App\Transcode\Infrastructure\FFmpeg\ProcessExecutor::requireNativeExecution();
    $clientId = getenv('BAANDER_NATIVE_CLIENT_ID');
    $clientGeneration = getenv('BAANDER_NATIVE_CLIENT_GENERATION');
    $generation = is_string($clientGeneration) ? filter_var($clientGeneration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if (!is_string($clientId) || preg_match('/\Amedia-(?:video-[0-2]|audio-0)\z/D', $clientId) !== 1
        || !str_starts_with($clientId, 'media-' . $kind . '-') || $generation === false
        || !is_string($clientGeneration) || preg_match('/\A[1-9][0-9]*\z/D', $clientGeneration) !== 1
    ) {
        throw new RuntimeException('Media job requires a native client launch fence.');
    }
    $native = \App\Shared\Infrastructure\Worker\NativeExecutionClient::fromEnvironment($clientId, $generation);
    if (($native->handshake()['ready'] ?? null) !== true) {
        throw new RuntimeException('Native execution peer is not ready.');
    }
    \App\Transcode\Infrastructure\FFmpeg\ProcessExecutor::configureNativeExecution($native);
    $authority = \App\Transcode\Infrastructure\Worker\DoctrineMediaWorkerAuthority::fromDsn($databaseUrl);
    $authority->assertActive();
    $kernel = new \App\Kernel($environment, $debug === '1');
    $kernel->boot();
    $container = $kernel->getContainer();
    $container->set(\App\Shared\Infrastructure\Worker\NativeExecutionClientInterface::class, $native);
    if ($kind === 'video') {
        $snapshots = $container->get('worker.media.encoder_snapshot_store');
        if (!$snapshots instanceof \App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore) {
            throw new RuntimeException('Media encoder snapshot store is not configured.');
        }
        $snapshot = $snapshots->read();
        if ($snapshot === null || $snapshot->workerIdentity !== $identity->bootId . ':' . $identity->launchGeneration) {
            throw new RuntimeException('Media job requires current worker encoder capabilities.');
        }
        $container->set('worker.media.encoder_profile', $snapshot->profile);
    }
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
            if (!$runtime instanceof \App\Transcode\Application\Port\TranscodeExecutionOwnershipPort) {
                throw new RuntimeException('Media video runtime is not configured.');
            }
            $claim = $runtime->claim($jobId, $identity);
            if ($claim !== null) {
                $executor = $container->get('transcode.loop_starter');
                if (!$executor instanceof \App\Transcode\Application\Port\TranscodeLoopStarterInterface) {
                    throw new RuntimeException('Media video executor is not configured.');
                }
                $qol = $container->get('worker.media.qol_lifecycle');
                if (!$qol instanceof \App\QoL\Infrastructure\Worker\WorkerQoLLifecycle) {
                    throw new RuntimeException('Media worker QoL lifecycle is not configured.');
                }
                \Swoole\Coroutine\run(static function () use ($executor, $claim, $stopRequested, $qol): void {
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
            if (!$runtime instanceof \App\Transcode\Application\Port\AudioRenditionRuntimePort) {
                throw new RuntimeException('Media audio runtime is not configured.');
            }
            $claim = $runtime->claim($key, $identity);
            if ($claim !== null) {
                $executor = $container->get('worker.media.audio_executor');
                if (!$executor instanceof \App\Transcode\Infrastructure\Transcode\AudioRenditionExecutor) {
                    throw new RuntimeException('Media audio executor is not configured.');
                }
                $executor->run($claim, $stopRequested);
            }
        }
    }
} catch (Throwable) {
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
