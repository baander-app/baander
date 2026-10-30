<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{EngineExecutor, ExecutionContext, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Fixtures\Failure;
use Baander\Tooling\Fixtures\Worker\{Command, ControlCapacity, LegacySupervisor, Production, Measurements, Reports};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerDrillTest extends TestCase
{
    /** @return iterable<string,array{list<array{name:string}>|false,list<string>}> */
    public static function registeredConsumerSets(): iterable
    {
        yield 'partial pool' => [[['name' => 'consumer-0']], ['consumer-1']];
        yield 'unrelated pool' => [[['name' => 'foreign']], ['consumer-0', 'consumer-1']];
        yield 'all expected and unrelated' => [[['name' => 'consumer-1'], ['name' => 'foreign'], ['name' => 'consumer-0']], []];
        yield 'unavailable registry' => [false, ['consumer-0', 'consumer-1']];
    }

    /** @param list<array{name:string}>|false $consumers
     * @param list<string> $missing
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('registeredConsumerSets')]
    public function testFixtureReadinessRequiresEveryConfiguredConsumer(array|false $consumers, array $missing): void
    {
        $runtime = new Runtime(dirname(__DIR__, 3), 5);
        try {
            $result = $runtime->run(
                [PHP_BINARY, 'tests/Fixtures/Worker/worker-command-check.php', 'consumer-readiness',
                    json_encode(['consumer-0', 'consumer-1'], JSON_THROW_ON_ERROR), json_encode($consumers, JSON_THROW_ON_ERROR)],
                env: array_replace($runtime->environment, ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'DATABASE_URL' => 'unavailable']),
                check: false,
            );
            self::assertSame($missing === [] ? 0 : 1, $result->exitCode, $result->stderr);
            $evidence = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($missing, $evidence['missing']);
            self::assertSame(['consumer-0', 'consumer-1'], $evidence['expected']);
            self::assertSame($consumers === false ? [] : array_column($consumers, 'name'), $evidence['observed']);
        } finally {
            $runtime->close();
        }
    }

    /** @return iterable<string,array{string,bool}> */
    public static function finalProducerOutcomes(): iterable
    {
        yield 'late final record' => ['complete', true];
        yield 'failed producer' => ['failed', false];
        yield 'truncated final record' => ['truncated', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('finalProducerOutcomes')]
    public function testFinalEvidenceWaitsForProducerAndRejectsFailedOrTruncatedOutput(string $mode, bool $success): void
    {
        $directory = sys_get_temp_dir() . '/baander-measurements-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $runtime = new Runtime(dirname(__DIR__, 3), 5);
        $docker = new \Baander\Tooling\Docker\Client(
            $runtime->executor,
            new \Baander\Tooling\Docker\Target('/usr/bin/docker', 'unix:///fixture.sock', 'fixture-daemon', null, null),
            [],
        );
        $measurements = new Measurements($runtime, $docker, $directory);
        $code = <<<'PHP_CHILD'
            echo "{\"sequence\":0}\n"; fflush(STDOUT);
            while (!is_file(getenv('BAANDER_TEST_SAMPLER_STOP_FILE'))) { usleep(1000); }
            usleep(10000);
            echo $argv[1] === 'truncated' ? '{' : "{\"sequence\":1}\n";
            exit($argv[1] === 'failed' ? 7 : 0);
            PHP_CHILD;
        $measurements->host([PHP_BINARY, '-r', $code, $mode], 'samples.jsonl', 'errors.log', 3);
        try {
            $runtime->await(static function () use ($directory): bool {
                clearstatcache(true, $directory . '/samples.jsonl');
                return is_file($directory . '/samples.jsonl') && filesize($directory . '/samples.jsonl') > 0;
            }, 2, .01);
            try {
                $measurements->finish();
                Reports::publish($directory . '/qualification.json', ['count' => count(Reports::samples($directory . '/samples.jsonl'))]);
                self::assertTrue($success, 'Failed collection published successful evidence');
                self::assertSame(2, Reports::json($directory . '/qualification.json')['count']);
            } catch (RuntimeException $error) {
                if ($success) {
                    throw $error;
                }
                self::assertFileDoesNotExist($directory . '/qualification.json');
            }
        } finally {
            $runtime->close();
            foreach (glob($directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function testCleanupFailureRetainsThePrimaryFailureMessage(): void
    {
        $error = new RuntimeException('resource cleanup failed', previous: new RuntimeException('original drill failed'));
        self::assertSame("original drill failed\nresource cleanup failed", Runtime::failure($error));
        self::assertSame(
            143,
            Runtime::status(new RuntimeException('cleanup failed', previous: new Failure('interrupted', 143))),
        );
    }

    public function testRuntimePreservesChildTimeoutAndSignalExitContracts(): void
    {
        $runner = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)));
        foreach (['exit' => 7, 'timeout' => 124, 'term' => 143, 'int' => 130] as $mode => $status) {
            $result = $runner->run(new ProcessSpec([PHP_BINARY, __DIR__ . '/Fixtures/worker-runtime.php', $mode]), new CompletionPolicy(null));
            self::assertSame($status, $result->exitCode, $mode . ': ' . $result->stdout . $result->stderr);
            self::assertNotSame('', $result->stderr);
        }
    }

    public function testMediaReadinessRequiresCurrentBootGenerationAndPid(): void
    {
        $identity = [
            'deploymentId' => 'baander.app:worker-fixture',
            'supervisorBootId' => 'current',
            'workerId' => 'media',
            'generation' => 1,
        ];
        $frame = ['event' => 'worker_media_ready', 'identity' => $identity, 'pid' => 42];
        $logs = "ordinary output\n{malformed\n" . json_encode($frame, JSON_THROW_ON_ERROR) . "\n";
        self::assertTrue(Command::mediaReady('{"media":42}', $logs, 'baander.app:worker-fixture', 'current'));
        self::assertFalse(Command::mediaReady('{"media":43}', $logs, 'baander.app:worker-fixture', 'current'));
        self::assertFalse(Command::mediaReady('{"media":42}', $logs, 'baander.app:worker-fixture', 'changed'));
        self::assertFalse(Command::mediaReady('{malformed', $logs, 'baander.app:worker-fixture', 'current'));
        self::assertFalse(Command::mediaReady('{}', $logs, 'baander.app:worker-fixture', 'current'));
        $frame['identity']['generation'] = 2;
        self::assertFalse(Command::mediaReady('{"media":42}', json_encode($frame, JSON_THROW_ON_ERROR), 'baander.app:worker-fixture', 'current'));
    }

    public function testStoppedSummaryAcceptsJsonKeyOrderButRejectsDuplicatesAndWrongCounts(): void
    {
        $valid = '{"exitCode":1,"event":"worker_supervisor_stopped","launchAttempts":4}';
        Command::validateStopped($valid, 1, 4);
        foreach ([
            $valid . "\n" . $valid,
            str_replace('"launchAttempts":4', '"launchAttempts":5', $valid),
            str_replace('"exitCode":1', '"exitCode":"1"', $valid),
            '{}',
        ] as $logs) {
            try {
                Command::validateStopped($logs, 1, 4);
                self::fail('Accepted wrong worker stop evidence');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('stop summary', $error->getMessage());
            }
        }
    }

    public function testProductionImageContractRejectsWebOrDevelopmentConfiguration(): void
    {
        $config = [
            'User' => 'www-data',
            'Entrypoint' => ['/usr/local/bin/start-worker.sh'],
            'WorkingDir' => '/qualified/application',
            'Cmd' => null,
            'Healthcheck' => ['Test' => ['NONE']],
            'Env' => ['APP_HOME=/qualified/application', 'APP_ENV=prod', 'APP_DEBUG=0'],
            'Volumes' => null,
        ];
        Production::validateConfiguration($config, '/qualified/application');
        foreach ([
            ['User' => 'root'],
            ['Entrypoint' => ['app:serve']],
            ['Entrypoint' => ['/usr/local/bin/php', '/qualified/application/bin/console', 'app:worker', '--no-interaction']],
            ['WorkingDir' => '/obsolete/application'],
            ['Env' => ['APP_ENV=prod', 'APP_DEBUG=0']],
            ['Env' => ['APP_HOME=/obsolete/application', 'APP_ENV=prod', 'APP_DEBUG=0']],
            ['Cmd' => ['web']],
            ['Healthcheck' => ['Test' => ['CMD', 'curl']]],
            ['Env' => ['APP_HOME=/qualified/application', 'APP_ENV=test', 'APP_DEBUG=0']],
            ['Env' => ['APP_HOME=/qualified/application', 'APP_ENV=prod', 'APP_DEBUG=1']],
            ['Volumes' => ['/source' => []]],
        ] as $change) {
            try {
                Production::validateConfiguration(array_replace($config, $change), '/qualified/application');
                self::fail('Accepted invalid production worker configuration');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testLegacySupervisorRelocatesEachDeclaredProgramDirectoryAtArgumentBoundaries(): void
    {
        $source = <<<'INI'
            [program:messenger-worker]
            directory=/historical/application
            command=/usr/local/bin/php /historical/application/bin/console messenger:consume async --keep=/historical/application /historical/application-archive/input
            [program:outbox-worker]
            directory=/historical/outbox
            command=/usr/local/bin/php /historical/outbox/bin/console app:outbox:consume
            INI;
        $configuration = parse_ini_string(LegacySupervisor::configuration($source, '/tmp/baander-runtime'), true, INI_SCANNER_RAW);
        self::assertSame('/tmp/baander-runtime', $configuration['program:messenger-worker']['directory']);
        self::assertSame('/tmp/baander-runtime', $configuration['program:outbox-worker']['directory']);
        self::assertSame(
            '/usr/local/bin/php /tmp/baander-runtime/bin/console messenger:consume async --keep=/historical/application /historical/application-archive/input',
            $configuration['program:messenger-worker']['command'],
        );
        self::assertSame('/usr/local/bin/php /tmp/baander-runtime/bin/console app:outbox:consume', $configuration['program:outbox-worker']['command']);
    }

    public function testLegacySupervisorConfigurationPreservesConsumerIdentityAndDisablesHttpAndAutorestart(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/tests/Fixtures/Worker/legacy-supervisord.conf');
        $result = parse_ini_string(LegacySupervisor::configuration($source, '/tmp/baander-runtime'), true, INI_SCANNER_RAW);
        self::assertArrayNotHasKey('program:swoole', $result);
        self::assertSame('false', $result['program:messenger-worker']['autorestart']);
        self::assertSame('/tmp/baander-runtime', $result['program:messenger-worker']['directory']);
        self::assertStringContainsString('/tmp/baander-runtime/bin/console messenger:consume', $result['program:messenger-worker']['command']);
        self::assertSame(
            'MESSENGER_CONSUMER_NAME="%(ENV_HOSTNAME)s-async-%(process_num)02d"',
            $result['program:messenger-worker']['environment'],
        );
        self::assertSame('unix:///tmp/worker-supervisord.sock', $result['supervisorctl']['serverurl']);
        self::assertSame('true', $result['program:outbox-worker']['autorestart']);
    }

    public function testCapacityBaselineRequiresElevenValidSamplesAndControlAuthority(): void
    {
        $directory = sys_get_temp_dir() . '/baander-capacity-baseline-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $runner = new EngineExecutor(ExecutionContext::bounded(120, getenv(), dirname(__DIR__, 3)));
        try {
            $sample = [
                'monotonic' => 1,
                'cpu.stat' => 'usage_usec 0',
                'memory.events' => 'oom_kill 0',
                'pids.events' => 'max 0',
                'heartbeat' => ['bootId' => 'fixture-boot', 'epoch' => 1],
                'lease' => ['owner_boot_id' => 'fixture-boot', 'epoch' => 1],
            ];
            foreach (['control', 'execution'] as $unit) {
                file_put_contents(
                    $directory . '/' . $unit . '-samples.jsonl',
                    str_repeat(json_encode($sample, JSON_THROW_ON_ERROR) . "\n", 11),
                );
            }
            self::assertSame(11, ControlCapacity::baseline($directory)['control']['validBaselineSamples']);
            $sample['lease'] = [];
            file_put_contents(
                $directory . '/control-samples.jsonl',
                str_repeat(json_encode($sample, JSON_THROW_ON_ERROR) . "\n", 12),
            );
            $this->expectExceptionMessage('eleven valid baseline samples');
            ControlCapacity::baseline($directory);
        } finally {
            $runner->run(new ProcessSpec(['rm', '-rf', '--', $directory]));
        }
    }

    public function testRuntimeStreamsObserversWhileWaitingAndRetainsSeparateJsonAndDiagnostics(): void
    {
        $directory = sys_get_temp_dir() . '/baander-worker-stream-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $runtime = new Runtime(dirname(__DIR__, 3), 5);
        try {
            $process = $runtime->start(
                [
                    PHP_BINARY,
                    '-r',
                    'for($i=0;$i<3;++$i){echo json_encode(["sample"=>$i]),"\n";fwrite(STDERR,"diagnostic\n");usleep(30000);}',
                ],
                logPath: $directory . '/samples.jsonl',
                stderrLogPath: $directory . '/errors.log',
            );
            $runtime->pause(.2);
            $result = $runtime->wait($process);
            self::assertSame('', $result->stdout);
            self::assertSame('', $result->stderr);
            self::assertCount(3, file($directory . '/samples.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertSame(str_repeat("diagnostic\n", 3), file_get_contents($directory . '/errors.log'));
            self::assertStringNotContainsString('diagnostic', file_get_contents($directory . '/samples.jsonl'));
        } finally {
            $runtime->cleaning = true;
            $runtime->run(['rm', '-rf', '--', $directory]);
            $runtime->close();
        }
    }
}
