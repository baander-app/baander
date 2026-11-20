<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests\Support;

use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\DevEnvironment\Host\ProcessEnvironment;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\Process\Execution\ProcessSpec;
use Baander\Process\Execution\RunningProcess;
use Baander\DevQualification\Fixtures\Worker\PostgresSampler;
use Baander\DevQualification\Fixtures\Worker\CompletionCursor;
use Baander\DevQualification\Fixtures\Worker\EvidenceMode;
use Baander\DevQualification\Fixtures\Worker\AutoscalingStatusObservation;
use Baander\DevQualification\Fixtures\Worker\KillOrderEvidence;
use Baander\Process\Execution\ProcessResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerSamplerRecordingRunner implements CommandExecutor
{
    /** @var list<non-empty-list<string>> */
    public array $calls = [];
    public function run(ProcessSpec $command, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        $this->calls[] = $command->argv;
        return new ProcessResult(0, null, false, false, 't', '', .01);
    }
    public function start(ProcessSpec $command): RunningProcess
    {
        throw new \LogicException('Unexpected start');
    }
    public function wait(RunningProcess $process, CompletionPolicy $policy = new CompletionPolicy()): ProcessResult
    {
        throw new \LogicException('Unexpected wait');
    }
    public function stop(RunningProcess $process, int $signal = 15): ProcessResult
    {
        throw new \LogicException('Unexpected stop');
    }
}
