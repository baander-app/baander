<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Runner;
use PHPUnit\Framework\TestCase;

final class WorkerOperatorNetworkTest extends TestCase
{
    public function testControllerDatabaseHasItsOwnPostgresNetworkAlias(): void
    {
        $directory = sys_get_temp_dir() . '/baander-operator-network-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        copy(__DIR__ . '/Fixtures/worker-operator-network-docker.php', $directory . '/bin/docker');
        chmod($directory . '/bin/docker', 0700);
        $runner = new Runner();
        try {
            $result = $runner->run(
                [PHP_BINARY, dirname(__DIR__, 2) . '/test-worker-operator-container.php'],
                env: array_replace(getenv(), [
                    'PATH' => $directory . '/bin:' . getenv('PATH'),
                    'DOCKER_CONTEXT' => 'fixture',
                    'BAANDER_TEST_OPERATOR_IN_CONTAINER' => '1',
                    'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
                    'BAANDER_TEST_USE_IMAGE' => '1',
                    'BAANDER_TEST_ARTIFACTS' => $directory . '/artifacts',
                    'WORKER_OPERATOR_NETWORK_CALLS' => $directory . '/calls.jsonl',
                ]),
                check: false,
            );
            self::assertSame(73, $result->exitCode, $result->stdout . $result->stderr);
            $calls = file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(
                    static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                    $lines,
                ));
            $database = array_find($calls, static fn(array $call): bool => $call[0] === 'run'
                && array_any($call, static fn(string $argument): bool => str_ends_with($argument, '-postgres')));
            self::assertNotNull($database);
            $network = $database[array_search('--network', $database, true) + 1];
            self::assertStringEndsWith('-control', $network);
            $alias = array_search('--network-alias', $database, true);
            self::assertNotFalse($alias, 'The controller database must resolve as postgres on its control network.');
            self::assertSame('postgres', $database[$alias + 1]);
        } finally {
            $runner->run(['rm', '-rf', '--', $directory]);
        }
    }
}
