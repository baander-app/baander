<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Build\ApplicationImage;
use Baander\Tooling\Docker\{Client, Target};
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

    private function layout(string $root): void
    {
        file_put_contents($this->source . '/packages/baander-tooling/resources/application-layout.json', json_encode(['version' => 1, 'root' => $root], JSON_THROW_ON_ERROR));
    }

    private function builder(ApplicationImageExecutor $executor): ApplicationImage
    {
        return new ApplicationImage(new Client($executor, new Target('/usr/bin/docker', 'unix:///run/docker.sock', 'qualification', null, null), []));
    }
}

final class ApplicationImageExecutor implements CommandExecutor
{
    /** @var list<ProcessSpec> */
    public array $commands = [];

    public function __construct(private readonly string $imageRoot) {}

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->commands[] = $command;
        $output = in_array('inspect', $command->argv, true) ? json_encode([[
            'Id' => 'sha256:' . str_repeat('a', 64),
            'Config' => ['Env' => ['APP_HOME=' . $this->imageRoot], 'WorkingDir' => $this->imageRoot, 'Labels' => ['org.baander.application-layout' => '{"version":1,"root":"/srv/baander"}']],
        ]], JSON_THROW_ON_ERROR) : '';
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
