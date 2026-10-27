<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Development\Runner;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Fixtures\Failure;
use Baander\Tooling\Fixtures\Worker\{Command, ControlCapacity, LegacySupervisor, Production};
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerDrillTest extends TestCase
{
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
        $runner = new Runner();
        foreach (['exit' => 7, 'timeout' => 124, 'term' => 143, 'int' => 130] as $mode => $status) {
            $result = $runner->run([PHP_BINARY, __DIR__ . '/Fixtures/worker-runtime.php', $mode], check: false);
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
            'Entrypoint' => ['/usr/local/bin/php', '/var/www/html/bin/console', 'app:worker', '--no-interaction'],
            'Cmd' => null,
            'Healthcheck' => ['Test' => ['NONE']],
            'Env' => ['APP_ENV=prod', 'APP_DEBUG=0'],
            'Volumes' => null,
        ];
        Production::validateConfiguration($config);
        foreach ([
            ['User' => 'root'],
            ['Entrypoint' => ['app:serve']],
            ['Cmd' => ['web']],
            ['Healthcheck' => ['Test' => ['CMD', 'curl']]],
            ['Env' => ['APP_ENV=test']],
            ['Volumes' => ['/source' => []]],
        ] as $change) {
            try {
                Production::validateConfiguration(array_replace($config, $change));
                self::fail('Accepted invalid production worker configuration');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
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
        $runner = new Runner();
        try {
            $sample = [
                'monotonic' => 1,
                'cpu.stat' => 'usage_usec 0',
                'memory.events' => 'oom_kill 0',
                'pids.events' => 'max 0',
                'heartbeat' => new \stdClass(),
                'lease' => new \stdClass(),
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
            $runner->run(['rm', '-rf', '--', $directory]);
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
