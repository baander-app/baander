<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/relay/tests/bootstrap.php';

use Baander\Registry\Qualification\DockerOwnership;
use Baander\RegistryTests\ContainerContract;
use Baander\RegistryTests\Runtime;

$root = $_SERVER['argv'][1];
$primary = (int) ($_SERVER['argv'][2] ?? '23');
$target = ['version' => 1, 'executable' => realpath(__DIR__ . '/registry-command.php'), 'endpoint' => 'unix:///var/run/docker.sock',
    'daemonId' => 'registry-fixture-daemon', 'originalContext' => null, 'snapshotDirectory' => null, 'transportArguments' => [], 'files' => []];
$environment = getenv();
$owner = new DockerOwnership(
    $root . '/nested-resources.json',
    $target,
    static fn(array $argv, ?string $input, float $timeout): array => Runtime::run($argv, $input, $timeout, $environment),
);
$owner->run(['network', 'create', 'baander-registry-nested-network']);
$owner->run(['volume', 'create', 'baander-registry-nested-volume']);
$owner->run(['create', '--name', 'baander-registry-nested-container', 'registry:qualification']);
mkdir($root . '/fixture', 0700);
$contract = new ContainerContract();
$reflection = new ReflectionObject($contract);
$reflection->getProperty('ownership')->setValue($contract, $owner);
$reflection->getProperty('directory')->setValue($contract, $root . '/fixture');
try {
    $failure = match ($primary) {
        0 => null,
        143 => new Baander\RegistryTests\Interrupted(15),
        default => new RuntimeException('primary gate failed', $primary),
    };
    $reflection->getMethod('finish')->invoke($contract, $failure);
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit($failure->getCode() ?: 1);
}
exit(99);
