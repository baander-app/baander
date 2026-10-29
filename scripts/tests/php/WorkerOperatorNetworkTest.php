<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Filesystem\AtomicFile;
use PHPUnit\Framework\TestCase;

final class WorkerOperatorNetworkTest extends TestCase
{
    public function testControllerDatabaseHasItsOwnPostgresNetworkAlias(): void
    {
        $directory = sys_get_temp_dir() . '/baander-operator-network-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        copy(__DIR__ . '/Fixtures/worker-operator-network-docker.php', $directory . '/bin/docker');
        chmod($directory . '/bin/docker', 0700);
        AtomicFile::writeJson($directory . '/target.json', new Target($directory . '/bin/docker', 'unix:///fixture.sock', 'fixture-daemon', null, null)->toArray());
        $environment = getenv();
        unset($environment['DOCKER_CONTEXT']);
        $runner = new EngineExecutor(ExecutionContext::bounded(240, $environment, dirname(__DIR__, 3)));
        try {
            $result = $runner->run(new ProcessSpec(
                [PHP_BINARY, dirname(__DIR__, 2) . '/test-worker-operator-container.php'],
                environment: array_replace($environment, [
                    'PATH' => $directory . '/bin:' . getenv('PATH'),
                    'BAANDER_TOOLING_TARGET_FILE' => $directory . '/target.json',
                    'DOCKER_HOST' => 'tcp://unselected.baander.app:2375',
                    'WORKER_OPERATOR_TRANSPORT_CALLS' => $directory . '/transport.jsonl',
                    'BAANDER_TEST_DOCKER_SOCKET_PATH' => '/fixture.sock',
                    'BAANDER_TEST_OPERATOR_IN_CONTAINER' => '1',
                    'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
                    'BAANDER_TEST_USE_IMAGE' => '1',
                    'BAANDER_TEST_ARTIFACTS' => $directory . '/artifacts',
                    'WORKER_OPERATOR_NETWORK_CALLS' => $directory . '/calls.jsonl',
                ]),
                replaceEnvironment: true,
            ), new CompletionPolicy(null));
            self::assertSame(73, $result->exitCode, $result->stdout . $result->stderr);
            $calls = file($directory . '/calls.jsonl', FILE_IGNORE_NEW_LINES)
                |> (static fn(array $lines): array => array_map(
                    static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                    $lines,
                ));
            foreach (file($directory . '/transport.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
                $transport = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(['--host', 'unix:///fixture.sock'], array_slice($transport, 0, 2));
            }
            $database = array_find($calls, static fn(array $call): bool => $call[0] === 'create'
                && array_any($call, static fn(string $argument): bool => str_ends_with($argument, '-postgres')));
            self::assertNotNull($database);
            $network = $database[array_search('--network', $database, true) + 1];
            self::assertStringEndsWith('-control', $network);
            $alias = array_search('--network-alias', $database, true);
            self::assertNotFalse($alias, 'The controller database must resolve as postgres on its control network.');
            self::assertSame('postgres', $database[$alias + 1]);
        } finally {
            try {
                $runner->run(new ProcessSpec(['rm', '-rf', '--', $directory]));
            } finally {
                $runner->close();
            }
        }
    }
}
