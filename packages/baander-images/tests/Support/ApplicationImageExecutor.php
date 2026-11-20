<?php

declare(strict_types=1);

namespace Baander\Images\Tests\Support;

use Baander\Images\Build\ApplicationImage;
use Baander\Images\Build\BuildOptions;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\Configuration\ToolingConfiguration;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use Baander\Process\Execution\RunningProcess;
use PHPUnit\Framework\TestCase;

final class ApplicationImageExecutor implements CommandExecutor
{
    /** @var list<ProcessSpec> */
    public array $commands = [];
    public int $imageEntries = 1;
    public string $builderDriver = 'docker-container';

    public function __construct(private readonly string $imageRoot) {}

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->commands[] = $command;
        if (array_slice($command->argv, 3, 2) === ['buildx', 'inspect']) {
            return new ProcessResult(0, null, false, false, "Name:          baander-ci\nDriver:        {$this->builderDriver}\n\nNodes:\nName:          baander-ci0\nEndpoint:      unix:///run/docker.sock\n", '', 0);
        }
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
