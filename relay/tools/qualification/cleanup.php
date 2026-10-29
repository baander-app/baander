#!/usr/bin/env php
<?php

declare(strict_types=1);

use Baander\Registry\Qualification\DockerOwnership;
use Baander\RegistryTests\Runtime;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Runtime::main(static function (): void {
    $options = Runtime::arguments(['receipt']);
    $receipt = $options['receipt'];
    $saved = DockerOwnership::read($receipt);
    $environment = getenv();
    foreach (['DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'] as $name) {
        unset($environment[$name]);
    }
    $owner = new DockerOwnership(
        $receipt,
        $saved['target'],
        static fn(array $argv, ?string $input, float $seconds): array => Runtime::run($argv, $input, $seconds, $environment),
    );
    Swoole\Process::signal(2, static function (): void {});
    Swoole\Process::signal(15, static function (): void {});
    $failures = $owner->cleanup(120);
    if ($failures !== []) {
        throw new RuntimeException(implode('; ', $failures) . '. Retry receipt: ' . $receipt);
    }
    echo "Owned relay resources are absent.\n";
});
