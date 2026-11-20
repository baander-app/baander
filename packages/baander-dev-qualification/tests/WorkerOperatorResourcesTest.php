<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests;

use Baander\Containers\Docker\Target;
use Baander\Filesystem\AtomicFile;
use Baander\DevQualification\Fixtures\Worker\OperatorCommands;
use Baander\DevQualification\Fixtures\Worker\OperatorConfiguration;
use Baander\DevQualification\Fixtures\Worker\OperatorResources;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerOperatorResourcesTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function operations(): iterable
    {
        yield 'stopped container' => ['create'];
        yield 'attached retained container' => ['start'];
        yield 'disposable container' => ['disposable'];
    }

    #[DataProvider('operations')]
    public function testContainerCommandOptionsArePassedUnchanged(string $operation): void
    {
        $directory = sys_get_temp_dir() . '/baander-operator-arguments-' . bin2hex(random_bytes(8));
        mkdir($directory . '/artifacts', 0700, true);
        copy(dirname(__DIR__, 3) . '/packages/baander-dev-qualification/tests/Fixtures/worker-operator-docker.php', $directory . '/docker');
        chmod($directory . '/docker', 0700);
        AtomicFile::writeJson($directory . '/target.json', new Target($directory . '/docker', 'unix:///fixture.sock', 'fixture-daemon', null, null)->toArray());
        AtomicFile::writeJson($directory . '/state.json', ['mode' => 'clean', 'calls' => [], 'queries' => [], 'resources' => ['container' => []]]);
        $environment = array_replace(getenv(), [
            'BAANDER_TOOLING_TARGET_FILE' => $directory . '/target.json',
            'BAANDER_DOCKER_EXECUTABLE' => $directory . '/ambient-docker-must-not-run',
            'WORKER_OPERATOR_STUB_STATE' => $directory . '/state.json',
        ]);
        $commands = new OperatorCommands(dirname(__DIR__, 3), $environment, 120);
        $resources = new OperatorResources($commands, $directory . '/artifacts');
        $arguments = ['--network', 'none', 'fixture-image', 'php', '-d', 'memory_limit=128M', 'probe.php', '--rm', '-d'];
        try {
            match ($operation) {
                'create' => $resources->createContainer('argument-probe', $arguments),
                'start' => $resources->startContainer('argument-probe', $arguments, attached: true),
                'disposable' => $resources->runContainer($arguments),
                default => throw new \LogicException('Unknown fixture operation: ' . $operation),
            };
            $fixture = OperatorConfiguration::json($directory . '/state.json');
            $create = array_find($fixture['calls'], static fn(array $call): bool => $call[0] === 'create');
            self::assertNotNull($create);
            self::assertSame($arguments, array_slice($create, -count($arguments)));
        } finally {
            try {
                self::assertSame([], $resources->cleanup());
            } finally {
                $commands->close();
                $cleanup = new EngineExecutor(ExecutionContext::bounded(30, $environment));
                try {
                    $cleanup->run(new ProcessSpec(['rm', '-rf', '--', $directory]));
                } finally {
                    $cleanup->close();
                }
            }
        }
    }
}
