#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Kernel;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\Worker\NativeExecutionClient;
use App\Shared\Infrastructure\Worker\NativeExecutionClientInterface;
use App\Transcode\Infrastructure\FFmpeg\HardwareCapabilitiesProber;
use App\Transcode\Infrastructure\FFmpeg\ProcessExecutor;
use App\Transcode\Infrastructure\FFmpeg\WorkerEncoderCapabilitiesSnapshotStore;
use App\Transcode\Infrastructure\Worker\DoctrineMediaWorkerAuthority;
use App\Transcode\Infrastructure\Worker\MediaWorkerEnvironment;
use App\Transcode\Infrastructure\Worker\MediaWorkerFailure;
use App\Transcode\Infrastructure\Worker\MediaWorkerRunner;
use Baander\Core\EnvironmentVariable;

// Private admitted child: inherited configuration only, never reload Dotenv files.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$kernel = null;
$exitCode = 0;
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $deploymentProfile = DeploymentProfile::fromEnvironment();
    ini_set('memory_limit', $deploymentProfile->phpHeapMiB() . 'M');
    if (PHP_SAPI !== 'cli' || count($_SERVER['argv'] ?? []) !== 1) {
        throw new RuntimeException('Media requires private supervisor admission.');
    }
    $identity = MediaWorkerEnvironment::identity();
    $environment = getenv(EnvironmentVariable::APP_ENV);
    $debug = getenv(EnvironmentVariable::APP_DEBUG);
    $controlDatabaseUrl = getenv(EnvironmentVariable::DATABASE_CONTROL_URL);
    if (!is_string($environment) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $environment) !== 1
        || !in_array($debug, ['0', '1'], true) || !is_string($controlDatabaseUrl) || $controlDatabaseUrl === ''
    ) {
        throw new RuntimeException('Media requires inherited kernel configuration.');
    }
    ProcessExecutor::requireNativeExecution();
    $native = NativeExecutionClient::fromEnvironment('media-control', $identity->launchGeneration);
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
    if ($deploymentProfile->videoEncoderSlots > 0) {
        $prober = $container->get('worker.media.hardware_prober');
        if (!$prober instanceof HardwareCapabilitiesProber) {
            throw new RuntimeException('Media hardware prober is not configured.');
        }
        $profile = $prober->probeForWorker();
        $authority->assertActive();
        $snapshots = $container->get('worker.media.encoder_snapshot_store');
        if (!$snapshots instanceof WorkerEncoderCapabilitiesSnapshotStore) {
            throw new RuntimeException('Media encoder snapshot store is not configured.');
        }
        $snapshots->publish($profile, $prober->getBitrateMultiplier(), $identity->bootId . ':' . $identity->launchGeneration);
    }
    $runner = $container->get('worker.media.runner');
    if (!$runner instanceof MediaWorkerRunner) {
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
} catch (Throwable $error) {
    fwrite(STDERR, "Media worker failed; deployment reconciliation is required.\n");
    fwrite(STDERR, json_encode([
        'event' => 'worker_media_failure',
        'exceptionClass' => $error::class,
        'sourceFile' => basename($error->getFile()),
        'sourceLine' => $error->getLine(),
        // Absent when the failure precedes the autoloader.
        ...(class_exists(MediaWorkerFailure::class) ? MediaWorkerFailure::reason($error) : []),
    ], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
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
