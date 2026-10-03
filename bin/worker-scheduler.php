#!/usr/bin/env php
<?php

declare(strict_types=1);

// Private admitted child: inherited configuration only, never reload Dotenv files.
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$kernel = null;
$exitCode = 0;
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    if (PHP_SAPI !== 'cli' || count($_SERVER['argv'] ?? []) !== 1 || getenv('BAANDER_WORKER_ID') !== 'scheduler'
        || getenv('BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES') !== '0'
    ) {
        throw new RuntimeException('Scheduler requires private supervisor admission.');
    }
    $namespace = getenv('BAANDER_WORKER_NAMESPACE');
    $bootId = getenv('BAANDER_WORKER_BOOT_ID');
    $generation = getenv('BAANDER_WORKER_GENERATION');
    $epoch = getenv('BAANDER_WORKER_LEASE_EPOCH');
    foreach ([$generation, $epoch] as $value) {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)
        ) {
            throw new RuntimeException('Scheduler requires bounded supervisor identity.');
        }
    }
    if (!is_string($namespace) || !is_string($bootId)) {
        throw new RuntimeException('Scheduler requires supervisor identity.');
    }
    new \App\Shared\Infrastructure\Worker\WorkerLaunchIdentity($namespace, $bootId, 'scheduler', (int) $generation);
    new \App\Shared\Infrastructure\Worker\DeploymentLease($namespace, $bootId, (int) $epoch);
    $environment = getenv('APP_ENV');
    $debug = getenv('APP_DEBUG');
    if (!is_string($environment) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $environment) !== 1
        || !in_array($debug, ['0', '1'], true)
    ) {
        throw new RuntimeException('Scheduler requires inherited kernel configuration.');
    }
    $kernel = new \App\Kernel($environment, $debug === '1');
    $kernel->boot();
    $runner = $kernel->getContainer()->get('scheduler.worker_runner');
    if (!$runner instanceof \App\Scheduler\Infrastructure\Process\SchedulerWorkerRunner) {
        throw new RuntimeException('Scheduler runner is not configured.');
    }
    $runner->runUntilSignalled();
} catch (Throwable) {
    fwrite(STDERR, "Scheduler worker failed; supervisor reconciliation is required.\n");
    $exitCode = 1;
} finally {
    try {
        $kernel?->shutdown();
    } catch (Throwable) {
        fwrite(STDERR, "Scheduler worker shutdown failed.\n");
        $exitCode = 1;
    }
}
exit($exitCode);
