<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Fixtures\Worker\{OperatorCleanup, OperatorCommands, OperatorConfiguration, OperatorQualification, OperatorRun, OperatorResources};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class WorkerCleanupTest extends TestCase
{
    private string $directory;
    private string|false $previousPath;
    /** @var array<string,string> */
    private array $environment;
    private string $statePath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-worker-cleanup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/bin', 0700);
        copy(__DIR__ . '/Fixtures/worker-operator-docker.php', $this->directory . '/bin/docker');
        chmod($this->directory . '/bin/docker', 0700);
        $this->previousPath = getenv('PATH');
        $this->statePath = $this->directory . '/state.json';
        $this->environment = array_replace(
            getenv(),
            [
                'PATH' => $this->directory . '/bin:' . $this->previousPath,
                'WORKER_OPERATOR_STUB_STATE' => $this->statePath,
                'BAANDER_TEST_OPERATOR_IN_CONTAINER' => '1',
                'BAANDER_TEST_DOCKER_SOCKET_PATH' => '/fixture.sock',
                'DOCKER_CONTEXT' => 'fixture',
                'BAANDER_TEST_ARTIFACTS' => $this->directory . '/artifacts',
            ],
        );
        $targetFile = $this->directory . '/target.json';
        \Baander\Tooling\Filesystem\AtomicFile::writeJson($targetFile, new \Baander\Tooling\Docker\Target($this->directory . '/bin/docker', 'unix:///fixture.sock', 'fixture-daemon', null, null)->toArray());
        $this->environment['BAANDER_TOOLING_TARGET_FILE'] = $targetFile;
        unset($this->environment['BAANDER_TEST_DOCKER_ENDPOINT']);
        putenv('PATH=' . $this->environment['PATH']);
    }

    protected function tearDown(): void
    {
        putenv($this->previousPath === false ? 'PATH' : 'PATH=' . $this->previousPath);
        new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)))->run(new ProcessSpec(['rm', '-rf', '--', $this->directory]));
    }

    /** @return array<string,list<string>> */
    private static function owned(): array
    {
        return [
            'container' => ['fixture-controller', 'worker-current', 'control-current', 'worker-retired', 'control-retired'],
            'network' => ['fixture'],
            'volume' => ['fixture-source-media'],
            'image' => ['fixture:controller'],
        ];
    }

    /** @return iterable<string,array{string,int,?string,int}> */
    public static function outcomes(): iterable
    {
        yield 'transport' => ['transport', 0, null, 1];
        yield 'clean' => ['clean', 0, null, 0];
        yield 'absent' => ['absent', 0, null, 0];
        yield 'verification transport' => ['verification-transport', 0, null, 1];
        yield 'remove failure' => ['remove-failure', 0, 'fixture-controller', 1];
        yield 'original failure' => ['remove-failure', 7, 'fixture-controller', 7];
        yield 'checkpoint failure' => ['checkpoint', 0, null, 1];
        yield 'preserved original failure' => ['clean', 7, null, 7];
        yield 'removal error despite absence' => ['remove-error-absent', 0, 'fixture-controller', 1];
        yield 'paused clean' => ['paused-clean', 0, null, 0];
        yield 'paused error' => ['paused-error', 0, null, 1];
        yield 'paused original error' => ['paused-error', 7, null, 7];
        foreach (self::owned() as $kind => $names) {
            yield 'false ' . $kind . ' removal' => ['false-removal', 0, $names[0], 1];
        }
    }

    /** @return iterable<string,array{string,string,list<string>}> */
    public static function identityEnumerations(): iterable
    {
        yield 'container' => ['container', '{"ID":{{json .ID}},"Names":{{json .Names}}}', ['ID', 'Names']];
        yield 'network' => ['network', '{"ID":{{json .ID}},"Name":{{json .Name}}}', ['ID', 'Name']];
        yield 'volume' => ['volume', '{"Name":{{json .Name}}}', ['Name']];
    }

    /** @param list<string> $fields */
    #[DataProvider('identityEnumerations')]
    public function testDockerFakeHonorsIdentityOnlyEnumeration(string $kind, string $format, array $fields): void
    {
        $this->state('clean', null);
        $commands = new OperatorCommands(dirname(__DIR__, 3), $this->environment, 60);
        try {
            $arguments = [$kind, 'ls', ...($kind === 'container' ? ['--all', '--no-trunc'] : ($kind === 'network' ? ['--no-trunc'] : [])), '--format', $format];
            $output = trim($commands->docker($arguments)->stdout);
            $rows = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", $output));
            self::assertNotEmpty($rows);
            foreach ($rows as $row) {
                self::assertEqualsCanonicalizing($fields, array_keys($row));
                if ($kind !== 'volume') {
                    self::assertSame(hash('sha256', $row[$kind === 'container' ? 'Names' : 'Name']), $row['ID']);
                }
            }
            self::assertContains('unrelated-' . $kind, array_column($rows, $kind === 'container' ? 'Names' : 'Name'));
        } finally {
            $commands->close();
        }
    }

    private function state(string $mode, ?string $blocked): OperatorRun
    {
        $resources = [];
        foreach (self::owned() as $kind => $names) {
            $resources[$kind] = [...($kind === 'container' ? ['worker-current', 'control-current', 'worker-retired', 'control-retired'] : []), 'unrelated-' . $kind];
        }
        OperatorConfiguration::write(
            $this->statePath,
            ['mode' => 'clean', 'blocked' => $blocked, 'resources' => $resources, 'calls' => [], 'queries' => []],
        );
        mkdir($this->directory . '/work', 0700);
        mkdir($this->directory . '/artifacts', 0700);
        $state = new OperatorRun();
        foreach ([
            'runId' => 'fixture',
            'namespace' => 'fixture-namespace',
            'workerName' => 'worker-current',
            'controlName' => 'control-current',
            'webName' => 'fixture-web',
            'fixtureImage' => 'fixture:fixture',
            'fixtureBase' => 'fixture:base',
            'controllerImage' => 'fixture:controller',
            'controllerCli' => 'fixture:cli',
            'sourceVolume' => 'fixture-source-media',
            'storageVolume' => 'fixture-storage',
            'stateVolume' => 'fixture-media-state',
            'secretsVolume' => 'fixture-web-secrets',
            'ipcVolume' => 'fixture-worker-ipc',
            'twoCommand' => !str_starts_with($mode, 'paused-'),
            'mediaObservation' => true,
            'lifecyclePhase' => str_starts_with($mode, 'paused-') ? 'redis' : '',
            'faultContainer' => str_starts_with($mode, 'paused-') ? hash('sha256', 'fixture-redis') : '',
            'work' => $this->directory . '/work',
            'artifacts' => $this->directory . '/artifacts',
        ] as $key => $value) {
            $state->$key = $value;
        }
        return $state;
    }

    private function recordedResources(OperatorCommands $commands, OperatorRun $state, string $mode): OperatorResources
    {
        $fixture = OperatorConfiguration::json($this->statePath);
        $target = $commands->client()->target->toArray();
        $token = str_repeat('a', 32);
        $recorded = [];
        $images = [];
        foreach (self::owned() as $kind => $names) {
            foreach ($names as $name) {
                if ($kind === 'container' && (str_starts_with($name, 'worker-') || str_starts_with($name, 'control-'))) {
                    continue;
                }
                $fixture['resources'][$kind][] = $name;
                $fixture['createdResources'][$kind][$name] = ['--label', 'org.baander.tooling.run=' . $token];
                if ($kind === 'image') {
                    $images[$name] = 'sha256:' . hash('sha256', $name);
                } else {
                    $recorded[] = ['kind' => $kind, 'name' => $name, 'id' => $kind === 'volume' ? $name : hash('sha256', $name)];
                }
            }
        }
        \Baander\Tooling\Filesystem\AtomicFile::writeJson($state->artifacts . '/resources.json', [
            'version' => 1, 'token' => $token, 'target' => $target, 'resources' => $recorded,
        ]);
        \Baander\Tooling\Filesystem\AtomicFile::writeJson($state->artifacts . '/images.json', [
            'version' => 1, 'token' => $token, 'target' => $target, 'images' => $images,
        ]);
        $fixture['mode'] = $mode;
        $fixture['calls'] = [];
        $fixture['queries'] = [];
        if ($mode === 'absent') {
            foreach (array_keys(self::owned()) as $kind) {
                $fixture['resources'][$kind] = ['unrelated-' . $kind];
            }
        }
        OperatorConfiguration::write($this->statePath, $fixture);
        return new OperatorResources($commands, $state->artifacts);
    }

    #[DataProvider('outcomes')]
    public function testCleanupProvesAbsencePreservesOriginalStatusAndUnrelatedResources(string $mode, int $original, ?string $blocked, int $expected): void
    {
        $state = $this->state($mode, $blocked);
        if ($mode === 'checkpoint') {
            $hook = $this->directory . '/checkpoint';
            file_put_contents($hook, "#!/bin/sh\nexit 9\n");
            chmod($hook, 0700);
            $this->environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT'] = $hook;
        } else {
            unset($this->environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT']);
        }
        $commands = new OperatorCommands(dirname(__DIR__, 3), $this->environment, 60);
        $resources = $this->recordedResources($commands, $state, $mode);
        try {
            self::assertSame($expected, new OperatorCleanup($commands, $state, $resources)->run($original));
        } finally {
            $commands->close();
        }
        $receipt = OperatorConfiguration::json($this->directory . '/artifacts/cleanup.json');
        self::assertSame($expected, $receipt['exitStatus']);
        self::assertSame(in_array($mode, ['clean', 'absent', 'paused-clean'], true), $receipt['cleanupVerified']);
        $final = OperatorConfiguration::json($this->statePath);
        if (str_starts_with($mode, 'paused-')) {
            self::assertSame(['unpause', hash('sha256', 'fixture-redis')], array_find($final['calls'], static fn(array $call): bool => $call[0] !== 'info'));
        }
        foreach ($final['calls'] as $call) {
            if (array_slice($call, 0, 3) === ['container', 'rm', '-f']) {
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $call[array_key_last($call)]);
            }
        }
        foreach (self::owned() as $kind => $names) {
            self::assertContains('unrelated-' . $kind, $final['resources'][$kind]);
            if (in_array($mode, ['clean', 'absent', 'checkpoint', 'paused-clean', 'paused-error'], true)) {
                self::assertSame(['unrelated-' . $kind], $final['resources'][$kind]);
            }
        }
        if ($mode === 'remove-failure') {
            self::assertSame(['unrelated-volume'], $final['resources']['volume']);
        }
    }

    public function testCleanupDoesNotInferOwnershipFromRunResourceNames(): void
    {
        $state = $this->state('clean', null);
        $fixture = OperatorConfiguration::json($this->statePath);
        foreach (self::owned() as $kind => $names) {
            $fixture['resources'][$kind] = [...$names, 'unrelated-' . $kind];
        }
        OperatorConfiguration::write($this->statePath, $fixture);
        $commands = new OperatorCommands(dirname(__DIR__, 3), $this->environment, 60);
        $resources = new OperatorResources($commands, $state->artifacts);
        try {
            self::assertSame(0, new OperatorCleanup($commands, $state, $resources)->run(0));
        } finally {
            $commands->close();
        }
        $final = OperatorConfiguration::json($this->statePath);
        foreach (self::owned() as $kind => $names) {
            $expected = array_values(array_filter($names, static fn(string $name): bool => $kind !== 'container'
                || (!str_starts_with($name, 'worker-') && !str_starts_with($name, 'control-'))));
            self::assertSame([...$expected, 'unrelated-' . $kind], $final['resources'][$kind]);
        }
    }

    /** @return iterable<string,array{string,bool}> */
    public static function phaseModes(): iterable
    {
        yield 'logged stdout' => ['logged', true];
        yield 'missing receipt' => ['missing', false];
        yield 'malformed receipt' => ['malformed', false];
        yield 'wrong phase' => ['wrong-phase', false];
        yield 'array evidence' => ['array-evidence', false];
        yield 'failed phase' => ['failed', false];
    }

    #[DataProvider('phaseModes')]
    public function testPhaseEvidenceIsIndependentOfLoggerStdout(string $mode, bool $expected): void
    {
        OperatorConfiguration::write(
            $this->statePath,
            ['mode' => $mode, 'calls' => [], 'queries' => [], 'resources' => [], 'outage' => true],
        );
        $q = new OperatorQualification(dirname(__DIR__, 3), $this->environment);
        $q->state->imageId = 'fixture-image';
        $q->state->appHome = '/fixture/application';
        $success = false;
        try {
            $q->combinedPhase('dependency');
            $success = true;
        } catch (Throwable $error) {
            if ($expected) {
                throw $error;
            }
        } finally {
            new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)))->run(new ProcessSpec(['rm', '-rf', '--', $q->state->work]));
            $q->commands->close();
        }
        self::assertSame($expected, $success);
        self::assertStringContainsString(
            'Redis cache unavailable',
            file_get_contents($q->artifact('dependency.stdout.log')),
        );
        if ($expected) {
            self::assertSame(
                503,
                OperatorConfiguration::json($q->artifact('dependency.json'))['evidence']['queueAdmissionStatus'],
            );
        }
    }

    /** @return iterable<string,array{bool}> */
    public static function finalStates(): iterable
    {
        yield 'worker stopped' => [true];
        yield 'worker draining' => [false];
    }

    #[DataProvider('finalStates')]
    public function testFinalDrillCapturesEncoderBeforeCancelAndOutage(bool $stopped): void
    {
        OperatorConfiguration::write(
            $this->statePath,
            [
                'mode' => 'logged',
                'calls' => [],
                'queries' => [],
                'resources' => ['container' => []],
                'stopped' => $stopped,
            ],
        );
        $hook = $this->directory . '/checkpoint';
        file_put_contents(
            $hook,
            <<<'PHP_HOOK'
            #!/usr/bin/env php
            <?php
            file_put_contents(getenv('WORKER_OPERATOR_CHECKPOINT_LOG'), $argv[1] . "\n", FILE_APPEND);
            PHP_HOOK,
        );
        chmod($hook, 0700);
        $this->environment['BAANDER_TEST_TWO_COMMAND_CHECKPOINT'] = $hook;
        $this->environment['WORKER_OPERATOR_CHECKPOINT_LOG'] = $this->directory . '/checkpoints';
        $q = new OperatorQualification(dirname(__DIR__, 3), $this->environment);
        $q->state->imageId = 'fixture-image';
        $q->state->appHome = '/fixture/application';
        $q->state->workerName = 'worker-current';
        $q->state->controlName = 'control-current';
        try {
            $q->scenario->finalDrill();
        } finally {
            new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)))->run(new ProcessSpec(['rm', '-rf', '--', $q->state->work]));
            $q->commands->close();
        }
        self::assertTrue(OperatorConfiguration::json($q->artifact('final-containment.json'))['contained']);
        self::assertSame("complete\n", file_get_contents($this->directory . '/checkpoints'));
        $calls = OperatorConfiguration::json($this->statePath)['calls'];
        $top = array_find_key($calls, static fn(array $call): bool => $call[0] === 'top');
        $cancel = array_find_key(
            $calls,
            static fn(array $call): bool => in_array('deployment.php', array_map(basename(...), $call), true) && $call[array_key_last($call)] === 'cancel',
        );
        $stop = array_find_key($calls, static fn(array $call): bool => $call[0] === 'stop');
        self::assertNotNull($top);
        self::assertNotNull($cancel);
        self::assertNotNull($stop);
        self::assertLessThan($cancel, $top);
        self::assertLessThan($stop, $cancel);
    }

    /** @return iterable<string,array{array<string,string>}> */
    public static function invalidModes(): iterable
    {
        yield 'invalid boolean' => [['BAANDER_TEST_OPERATOR_IN_CONTAINER' => 'true']];
        yield 'development source requires container' => [['BAANDER_TEST_DEV_SOURCE_BIND' => '1']];
        yield 'invalid lifecycle phase' => [['BAANDER_TEST_WORKER_LIFECYCLE_PHASE' => 'cache']];
        yield 'lifecycle excludes combined web' => [['BAANDER_TEST_WORKER_LIFECYCLE_PHASE' => 'redis', 'BAANDER_TEST_TWO_COMMAND' => '1']];
        yield 'browser requires checkpoint' => [['BAANDER_TEST_BROWSER_CLIENT_CONTROLS' => '1', 'BAANDER_TEST_TWO_COMMAND' => '1']];
        yield 'direct image requires container' => [['BAANDER_TEST_USE_IMAGE' => '1']];
        yield 'observation requires fixture image' => [[
            'BAANDER_TEST_OPERATOR_IN_CONTAINER' => '1',
            'BAANDER_TEST_DOCKER_SOCKET_PATH' => '/fixture.sock',
            'BAANDER_TEST_CHECKOUT_IN_IMAGE' => '1',
            'BAANDER_TEST_USE_IMAGE' => '1',
            'BAANDER_TEST_MEDIA_CONTROL_OBSERVATION' => '1',
        ]];
    }

    /** @param array<string,string> $environment */
    #[DataProvider('invalidModes')]
    public function testInvalidModesFailBeforeAnyDockerOrWorkspaceMutation(array $environment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OperatorConfiguration($environment);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidRuntimeFlags(): iterable
    {
        yield 'operator mode' => ['BAANDER_TEST_OPERATOR_IN_CONTAINER'];
        yield 'direct image mode' => ['BAANDER_TEST_USE_IMAGE'];
    }

    #[DataProvider('invalidRuntimeFlags')]
    public function testCliPreservesRuntimeConfigurationFailureStatus(string $flag): void
    {
        $environment = array_replace($this->environment, [$flag => '2']);
        $result = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)))->run(new ProcessSpec([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/test-worker-operator-container.php',
        ], environment: $environment), new CompletionPolicy(null));
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString($flag . ' must be 0 or 1', $result->stderr);
        self::assertFileDoesNotExist($this->statePath);
    }

    /** @return iterable<string,array{string,int}> */
    public static function commandOutcomes(): iterable
    {
        yield 'command failure' => ['failure', 7];
        yield 'deadline' => ['timeout', 124];
        yield 'interrupt' => ['interrupt', 130];
        yield 'termination' => ['terminate', 143];
    }

    #[DataProvider('commandOutcomes')]
    public function testCommandExitTimeoutAndSignalStatusesRemainDistinct(string $mode, int $expected): void
    {
        $harness = $this->directory . '/status.php';
        file_put_contents(
            $harness,
            <<<'PHP_HARNESS'
            <?php
            declare(strict_types=1);
            require $argv[1] . '/scripts/bootstrap.php';
            $mode = $argv[2];
            $commands = new Baander\Tooling\Fixtures\Worker\OperatorCommands($argv[1], getenv(), $mode === 'timeout' ? 1 : 60);
            $signal = $mode === 'interrupt' ? 2 : 15;
            $code = match ($mode) {
                'failure' => 'echo "status ready"; exit(7);',
                'timeout' => 'echo "status ready"; sleep(3);',
                default => 'echo "status ready"; $stat = file_get_contents("/proc/self/stat"); $fields = explode(" ", substr($stat, strrpos($stat, ")") + 2)); Swoole\\Process::kill((int) $fields[1], ' . $signal . '); sleep(3);',
            };
            $status = 0;
            try {
                $commands->run([PHP_BINARY, '-r', $code], log: getenv('WORKER_OPERATOR_STATUS_LOG'));
            } catch (Throwable $error) {
                $status = Baander\Tooling\Fixtures\Runtime::status($error);
            } finally {
                $commands->close();
            }
            exit($status);
            PHP_HARNESS,
        );
        $environment = array_replace($this->environment, ['WORKER_OPERATOR_STATUS_LOG' => $this->directory . '/status.log']);
        $result = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)))->run(new ProcessSpec([PHP_BINARY, $harness, dirname(__DIR__, 3), $mode], environment: $environment), new CompletionPolicy(null));
        self::assertSame($expected, $result->exitCode, $result->stdout . $result->stderr);
        self::assertSame('status ready', file_get_contents($this->directory . '/status.log'));
    }
}
