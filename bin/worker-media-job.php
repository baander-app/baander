#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$kernel = null;
$pool = null;
$poolGroups = [];
$poolUncertain = false;
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
    ini_set('memory_limit', $kind === 'video' ? '128M' : '256M');
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
    if ($kind === 'video') {
        // Fork the one helper before opening any kernel, Doctrine or Redis connection.
        $pool = new \App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool(
            [new \App\Transcode\Infrastructure\Swoole\TranscodePoolWorker()], 1, new \Psr\Log\NullLogger(), resultTableSize: 64,
        );
        $pool->boot();
        $poolGroups = $pool->workerPids();
        if (count($poolGroups) !== 1 || !function_exists('posix_kill')) {
            throw new RuntimeException('Media job requires an owned pool process group.');
        }
    }
    $authority = \App\Transcode\Infrastructure\Worker\DoctrineMediaWorkerAuthority::fromDsn($databaseUrl);
    $authority->assertActive();
    $kernel = new \App\Kernel($environment, $debug === '1');
    $kernel->boot();
    $container = $kernel->getContainer();
    if ($pool !== null) {
        $container->set('worker.media.cpu_pool', $pool);
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
    $stopRequested = static function () use (&$stopping, &$poolUncertain, &$authorityChecking, $poolGroups, $authority): bool {
        foreach ($poolGroups as $pid) {
            $info = [];
            if (!pcntl_waitid(P_PID, $pid, $info, WEXITED | WNOHANG | WNOWAIT) || ($info['pid'] ?? 0) === $pid) {
                $poolUncertain = true;
                $stopping = true;
            }
        }
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
                \Swoole\Coroutine\run(static function () use ($executor, $claim, $stopRequested): void {
                    $executor->run($claim, $stopRequested);
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
        // Success is acknowledged only after every pool child and process group is reaped.
        $pool?->shutdown();
        foreach ($poolGroups as $group) {
            if (posix_kill(-$group, 0) || posix_get_last_error() !== 3) {
                throw new RuntimeException('Media pool process group exit is unconfirmed.');
            }
        }
        if ($poolUncertain) {
            throw new RuntimeException('Media pool child exited unexpectedly.');
        }
    } catch (Throwable) {
        fwrite(STDERR, "Media execution pool shutdown is unconfirmed.\n");
        $exitCode = 1;
    }
    try {
        $kernel?->shutdown();
    } catch (Throwable) {
        fwrite(STDERR, "Media execution kernel shutdown failed.\n");
        $exitCode = 1;
    }
}
exit($exitCode);
