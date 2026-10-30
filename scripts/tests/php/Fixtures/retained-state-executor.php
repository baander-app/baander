<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{CommandExecutor, CompletionPolicy, ProcessResult, ProcessSpec, RunningProcess};

final class RetainedStateExecutor implements CommandExecutor
{
    /** @var list<ProcessSpec> */
    public array $commands = [];
    public string $daemonId = 'retained-daemon';
    public bool $imagesAvailable = true;

    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->commands[] = $command;
        $arguments = $command->argv;
        $output = '';
        $status = 0;
        if (in_array('context', $arguments, true) && in_array('inspect', $arguments, true)) {
            $output = json_encode([['Endpoints' => ['docker' => ['Host' => 'unix:///tmp/baander.app-retained.sock']]]], JSON_THROW_ON_ERROR);
        } elseif (in_array('info', $arguments, true)) {
            $output = json_encode(['ID' => $this->daemonId], JSON_THROW_ON_ERROR);
        } elseif (in_array('image', $arguments, true) && in_array('inspect', $arguments, true)) {
            $output = json_encode([['Id' => $arguments[array_key_last($arguments)],
                'Config' => ['WorkingDir' => '/var/www/html', 'Env' => ['APP_HOME=/var/www/html'], 'Labels' => []]]], JSON_THROW_ON_ERROR);
            $status = $this->imagesAvailable ? 0 : 1;
        } else {
            throw new \LogicException('Unexpected conversion command: ' . implode(' ', $arguments));
        }
        return $policy->complete(new ProcessResult($status, null, false, false, $output, $status === 0 ? '' : 'Image unavailable', 0), $command);
    }

    public function start(ProcessSpec $command): RunningProcess
    {
        throw new \LogicException('Unexpected asynchronous conversion command.');
    }

    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        throw new \LogicException('Unexpected asynchronous conversion wait.');
    }

    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        throw new \LogicException('Unexpected asynchronous conversion stop.');
    }
}
