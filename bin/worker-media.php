#!/usr/bin/env php
<?php

declare(strict_types=1);

// Private admitted child: inherited configuration only, never reload Dotenv files.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('memory_limit', '256M');
$kernel = null;
$exitCode = 0;
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    if (PHP_SAPI !== 'cli' || count($_SERVER['argv'] ?? []) !== 1) {
        throw new RuntimeException('Media requires private supervisor admission.');
    }
    $identity = \App\Transcode\Infrastructure\Worker\MediaWorkerEnvironment::identity();
    $environment = getenv('APP_ENV');
    $debug = getenv('APP_DEBUG');
    $databaseUrl = getenv('DATABASE_URL');
    if (!is_string($environment) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $environment) !== 1
        || !in_array($debug, ['0', '1'], true) || !is_string($databaseUrl) || $databaseUrl === ''
    ) {
        throw new RuntimeException('Media requires inherited kernel configuration.');
    }
    \App\Transcode\Infrastructure\FFmpeg\ProcessExecutor::requireNativeExecution();
    $native = \App\Shared\Infrastructure\Worker\NativeExecutionClient::fromEnvironment('media-control', $identity->launchGeneration);
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
    $prober = $container->get('worker.media.hardware_prober');
    if (!$prober instanceof \App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber) {
        throw new RuntimeException('Media hardware prober is not configured.');
    }
    $profile = $prober->probeForWorker();
    $authority->assertActive();
    $snapshots = $container->get('worker.media.encoder_snapshot_store');
    if (!$snapshots instanceof \App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore) {
        throw new RuntimeException('Media encoder snapshot store is not configured.');
    }
    $snapshots->publish($profile, $prober->getBitrateMultiplier(), $identity->bootId . ':' . $identity->launchGeneration);
    $runner = $container->get('worker.media.runner');
    if (!$runner instanceof \App\Transcode\Infrastructure\Worker\MediaWorkerRunner) {
        throw new RuntimeException('Media runner is not configured.');
    }
    $runner->runUntilSignalled(static function () use ($identity): void {
        fwrite(STDOUT, json_encode([
            'event' => 'worker_media_ready',
            'pid' => getmypid(),
            'identity' => [
                'deploymentId' => $identity->namespace,
                'supervisorBootId' => $identity->bootId,
                'workerId' => $identity->workerId,
                'generation' => $identity->launchGeneration,
            ],
        ], JSON_THROW_ON_ERROR) . "\n");
    });
} catch (Throwable) {
    fwrite(STDERR, "Media worker failed; deployment reconciliation is required.\n");
    $exitCode = 1;
} finally {
    try {
        $kernel?->shutdown();
    } catch (Throwable) {
        fwrite(STDERR, "Media worker shutdown failed.\n");
        $exitCode = 1;
    }
}
exit($exitCode);
