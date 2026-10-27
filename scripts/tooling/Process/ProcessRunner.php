<?php

declare(strict_types=1);

namespace Baander\Tooling\Process;

use InvalidArgumentException;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;

final class ProcessRunner
{
    /** @var array<int, RunningProcess> */
    private array $processes = [];

    /** @var \WeakMap<RunningProcess, true> */
    private \WeakMap $owned;

    public function __construct()
    {
        $this->owned = new \WeakMap();
    }

    public function start(ProcessSpec $spec): RunningProcess
    {
        $process = new RunningProcess($spec);
        $this->processes[spl_object_id($process)] = $process;
        $this->owned[$process] = true;
        return $process;
    }

    public function run(ProcessSpec $spec): ProcessResult
    {
        return $this->wait($this->start($spec));
    }

    public function wait(RunningProcess $process): ProcessResult
    {
        if (!isset($this->owned[$process])) {
            throw new InvalidArgumentException('This process belongs to another runner.');
        }
        $forward = function (int $signal): void {
            foreach ($this->processes as $running) {
                $running->cancel($signal);
            }
        };
        Process::signal(2, $forward);
        Process::signal(15, $forward);
        $failure = null;
        $timer = Timer::tick(5, function () use (&$failure): void {
            try {
                foreach ($this->processes as $id => $running) {
                    $running->pump();
                    if ($running->result() !== null) {
                        unset($this->processes[$id]);
                    }
                }
            } catch (\Throwable $error) {
                $failure = $error;
            }
        });
        try {
            do {
                Event::dispatch();
                if ($failure !== null) {
                    throw $failure;
                }
                $result = $process->result();
            } while ($result === null);
            return $result;
        } catch (\Throwable $error) {
            foreach ($this->processes as $running) {
                $running->dispose();
            }
            $this->processes = [];
            throw $error;
        } finally {
            Timer::clear($timer);
            Process::signal(2, null);
            Process::signal(15, null);
            // Finalize an idle reactor explicitly to avoid Swoole's deprecated
            // implicit shutdown pump. Leave timers owned by the caller intact.
            if (Timer::stats()['num'] === 0) {
                Event::wait();
            }
        }
    }
}
