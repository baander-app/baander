<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Build\{ApplicationImage, BuildOptions};
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, ProcessResult, ProcessSpec, RunningProcess};
use PHPUnit\Framework\TestCase;

final class ApplicationBuildContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = sys_get_temp_dir() . '/baander.app-build-contract-' . bin2hex(random_bytes(8));
        mkdir($this->source . '/packages/baander-tooling/resources', 0700, true);
    }

    protected function tearDown(): void
    {
        $file = $this->source . '/packages/baander-tooling/resources/application-layout.json';
        if (is_file($file)) {
            unlink($file);
        }
        foreach (glob($this->source . '/*.fixture') ?: [] as $fixture) {
            unlink($fixture);
        }
        foreach (['/packages/baander-tooling/resources', '/packages/baander-tooling', '/packages', ''] as $suffix) {
            rmdir($this->source . $suffix);
        }
    }

    public function testSelectedSourceWithoutVendorSuppliesBuildLayoutAndMetadata(): void
    {
        $this->layout('/srv/baander');
        $executor = new ApplicationImageExecutor('/srv/baander');
        $builder = $this->builder($executor);
        self::assertSame('sha256:' . str_repeat('a', 64), $builder->build($this->source, 'ci', 'revision', 'baander-build:qualification'));
        $arguments = $executor->commands[0]->argv;
        self::assertContains('APP_HOME=/srv/baander', $arguments);
        self::assertContains('org.baander.application-layout={"version":1,"root":"/srv/baander"}', $arguments);
        self::assertContains('BAANDER_SOURCE_REVISION=revision', $arguments);
        self::assertSame($this->source, end($arguments));
        self::assertFileDoesNotExist($this->source . '/vendor/autoload.php');
    }

    public function testMissingSourceLayoutFailsBeforeDockerBuild(): void
    {
        $executor = new ApplicationImageExecutor('/srv/baander');
        try {
            $this->builder($executor)->build($this->source, 'ci', 'revision', 'baander-build:qualification');
            self::fail('Missing source layout was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('configuration', $error->getMessage());
            self::assertSame([], $executor->commands);
        }
    }

    public function testIncompatibleBuiltImageIsRejectedBeforeReturningItsIdentity(): void
    {
        $this->layout('/srv/baander');
        $this->expectExceptionMessage('layout');
        $this->builder(new ApplicationImageExecutor('/wrong-root'))->build($this->source, 'worker', 'revision', 'baander-build:qualification');
    }

    public function testBuildxPreservesTagsCacheAndSelectedHelperSource(): void
    {
        $this->layout('/srv/baander');
        $executor = new ApplicationImageExecutor('/srv/baander');
        $this->builder($executor)->build($this->source, 'dev-operator', 'revision', 'baander-build:qualification', options: new BuildOptions(
            buildx: true,
            additionalTags: ['baander-build:latest'],
            cacheFrom: ['type=local,src=/tmp/cache'],
            cacheTo: ['type=local,dest=/tmp/cache,mode=max'],
        ));
        $arguments = $executor->commands[0]->argv;
        self::assertContains('buildx', $arguments);
        self::assertContains('--load', $arguments);
        self::assertContains('baander-build:latest', $arguments);
        self::assertContains('type=local,src=/tmp/cache', $arguments);
        self::assertContains('type=local,dest=/tmp/cache,mode=max', $arguments);
        self::assertContains('OPERATOR_HELPER_IMAGE=registry.baander.app/docker:qualification', $arguments);
    }

    public function testRuntimeEntrypointsUseSelectedRootArgumentsAndReplaceTheirShell(): void
    {
        $php = $this->source . '/php.fixture';
        file_put_contents($php, '#!/bin/sh' . "\nprintf '%s\\n' \"$$\" \"$@\"\n");
        chmod($php, 0700);
        symlink($php, $this->source . '/php');
        try {
            foreach ([
                'web' => ['/srv/baander/bin/console', 'app:serve', '--no-interaction', '--qualification'],
                'worker' => ['/srv/baander/bin/console', 'app:worker', '--no-interaction', '--qualification'],
                'operator' => ['/srv/baander/bin/worker-deployment.php', '--qualification'],
            ] as $role => $expected) {
                $script = dirname(__DIR__, 3) . '/docker/general/start-' . $role . '.sh';
                $result = new \Baander\Tooling\Process\ProcessRunner()->run(new ProcessSpec(
                    ['/bin/sh', '-c', 'echo $$; exec /bin/sh "$1" --qualification', 'entrypoint', $script],
                    environment: ['PATH' => $this->source . ':/usr/bin:/bin', 'APP_HOME' => '/srv/baander', 'APP_ENV' => 'prod'],
                ));
                self::assertSame(0, $result->exitCode);
                $lines = explode("\n", trim($result->stdout));
                self::assertSame($lines[0], $lines[1], $role . ' must preserve PID 1 through exec.');
                self::assertSame($expected, array_slice($lines, 2));
            }
        } finally {
            unlink($this->source . '/php');
        }
    }

    public function testSupportedCommandExportsValidatedImmutableComposeInputs(): void
    {
        $this->layout('/srv/baander');
        $docker = $this->source . '/docker.fixture';
        file_put_contents($docker, <<<'PHP'
#!/usr/bin/env php
<?php
if (in_array('info', $argv, true)) {
    echo '{"ID":"build-fixture"}';
} elseif (in_array('inspect', $argv, true)) {
    echo json_encode([['Id' => 'sha256:' . str_repeat('b', 64), 'Config' => [
        'Env' => ['APP_HOME=/srv/baander'], 'WorkingDir' => '/srv/baander',
        'Labels' => ['org.baander.application-layout' => '{"version":1,"root":"/srv/baander"}'],
    ]]]);
} elseif (!in_array('APP_HOME=/srv/baander', $argv, true)) {
    exit(11);
}
PHP);
        chmod($docker, 0700);
        $environmentFile = $this->source . '/compose.fixture';
        $result = new \Baander\Tooling\Process\ProcessRunner()->run(new ProcessSpec(
            [PHP_BINARY, dirname(__DIR__, 2) . '/build-application.php', '--source', $this->source,
                '--target', 'dev', '--tag', 'baander:qualification', '--compose-env', $environmentFile],
            environment: ['BAANDER_DOCKER_EXECUTABLE' => $docker, 'DOCKER_HOST' => 'unix:///run/baander-build-fixture.sock'],
        ));
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame('BAANDER_APPLICATION_IMAGE=sha256:' . str_repeat('b', 64) . "\nAPP_HOME=/srv/baander\n", file_get_contents($environmentFile));
    }

    public function testImageInspectionMustContainExactlyOneImage(): void
    {
        $this->layout('/srv/baander');
        $executor = new ApplicationImageExecutor('/srv/baander');
        $executor->imageEntries = 2;
        $this->expectExceptionMessage('exactly one image');
        $this->builder($executor)->build($this->source, 'worker', 'revision', 'baander-build:qualification');
    }

    public function testFailedCommandRetainsItsBuildLogAndReportsItsPath(): void
    {
        $this->layout('/srv/baander');
        $docker = $this->source . '/docker-failure.fixture';
        file_put_contents($docker, <<<'PHP'
#!/usr/bin/env php
<?php
if (in_array('info', $argv, true)) {
    echo '{"ID":"build-fixture"}';
} else {
    fwrite(STDERR, "qualification build failure\n");
    exit(9);
}
PHP);
        chmod($docker, 0700);
        $result = new \Baander\Tooling\Process\ProcessRunner()->run(new ProcessSpec(
            [PHP_BINARY, dirname(__DIR__, 2) . '/build-application.php', '--source', $this->source, '--target', 'dev', '--tag', 'baander:qualification'],
            environment: ['BAANDER_DOCKER_EXECUTABLE' => $docker, 'DOCKER_HOST' => 'unix:///run/baander-build-fixture.sock'],
        ));
        self::assertSame(9, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertMatchesRegularExpression('~See (/[^\s]+/build\.log)~', $result->stderr);
        preg_match('~See (/[^\s]+/build\.log)~', $result->stderr, $matches);
        $log = $matches[1];
        try {
            self::assertStringContainsString('qualification build failure', file_get_contents($log));
            self::assertSame(['build.log'], array_values(array_diff(scandir(dirname($log)), ['.', '..'])));
        } finally {
            unlink($log);
            rmdir(dirname($log));
        }
    }

    private function layout(string $root): void
    {
        file_put_contents($this->source . '/packages/baander-tooling/resources/application-layout.json', json_encode(['version' => 1, 'root' => $root], JSON_THROW_ON_ERROR));
    }

    private function builder(ApplicationImageExecutor $executor): ApplicationImage
    {
        return new ApplicationImage(new Client($executor, new Target('/usr/bin/docker', 'unix:///run/docker.sock', 'qualification', null, null), []), ToolingConfiguration::resolve(['images' => ['operatorHelper' => ['reference' => 'registry.baander.app/docker:qualification']]])->images);
    }
}

final class ApplicationImageExecutor implements CommandExecutor
{
    /** @var list<ProcessSpec> */
    public array $commands = [];
    public int $imageEntries = 1;

    public function __construct(private readonly string $imageRoot) {}

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->commands[] = $command;
        $output = in_array('inspect', $command->argv, true) ? json_encode(array_fill(0, $this->imageEntries, [
            'Id' => 'sha256:' . str_repeat('a', 64),
            'Config' => ['Env' => ['APP_HOME=' . $this->imageRoot], 'WorkingDir' => $this->imageRoot, 'Labels' => ['org.baander.application-layout' => '{"version":1,"root":"/srv/baander"}']],
        ]), JSON_THROW_ON_ERROR) : '';
        return new ProcessResult(0, null, false, false, $output, '', 0);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        throw new \LogicException('This build fixture uses synchronous commands.');
    }

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        throw new \LogicException('This build fixture uses synchronous commands.');
    }

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        throw new \LogicException('This build fixture uses synchronous commands.');
    }
}
