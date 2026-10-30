<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Process\{CommandFailure, CompletionPolicy, ProcessResult};
use Throwable;

final readonly class ContainedSamplerDrain
{
    /** @param array{control:array{name:string,id:string},execution:array{name:string,id:string}} $units */
    public function __construct(private string $artifacts, private string $phase, private array $units)
    {
        Reports::require(in_array($phase, ['memory', 'pid'], true), 'Contained sampler completion requires an exhaustion phase');
    }

    public function complete(string $container, string $output, ProcessResult $result): void
    {
        $this->completeProbe($result, function () use ($container, $output): void {
            $role = $container === $this->units['control']['name'] ? 'control'
                : ($container === $this->units['execution']['name'] ? 'execution' : null);
            Reports::require($role !== null && $output === $role . '-samples.jsonl', 'Interrupted sampler does not belong to the contained pair');
            $this->verify();
        });
    }

    public function completePressure(ProcessResult $result): void
    {
        $this->completeProbe($result, function (): void {
            if ($this->phase === 'memory') {
                $records = Reports::samples($this->artifacts . '/pressure.log');
                $targets = array_filter($records, static fn(array $row): bool => isset($row['oomTarget']));
                $deaths = array_filter($records, static fn(array $row): bool => isset($row['oomTargetDied']));
                Reports::require(count($targets) === 1 && count($deaths) === 1, 'Memory pressure lacks one exact native target and terminal death');
                $targetIndex = array_key_first($targets);
                $deathIndex = array_key_first($deaths);
                $target = $targets[$targetIndex]['oomTarget'];
                $dead = $deaths[$deathIndex]['oomTargetDied'];
                Reports::require(
                    is_array($target) && is_int($target['pid'] ?? null) && $target['pid'] > 0
                    && is_string($target['startTicks'] ?? null) && ctype_digit($target['startTicks'])
                    && is_array($target['argv'] ?? null) && array_is_list($target['argv']) && $target['argv'] !== []
                    && array_all($target['argv'], static fn(mixed $argument): bool => is_string($argument) && $argument !== '')
                    && $targetIndex < $deathIndex && $target === $dead,
                    'Memory pressure terminal death differs from the selected native identity',
                );
            }
            $this->verify();
        });
    }

    public function completeOwnedFailure(ProcessResult $result): void
    {
        $this->completeProbe($result, function (): void {
            $records = Reports::samples($this->artifacts . '/owned-failure.jsonl');
            Reports::require($records !== [], 'Owned failure probe lacks terminal evidence');
            $terminal = $records[array_key_last($records)];
            $baseline = Reports::samples($this->artifacts . '/control-samples.jsonl')[0]['heartbeat'] ?? [];
            Reports::require(
                is_string($baseline['namespace'] ?? null) && is_string($baseline['bootId'] ?? null) && is_int($baseline['epoch'] ?? null),
                'Owned failure probe lacks baseline authority',
            );
            Reports::require(
                ($terminal['phase'] ?? null) === $this->phase && is_array($terminal['identity'] ?? null)
                && Reports::identity($terminal['identity'], $baseline)
                && is_int($terminal['attempts'] ?? null) && $terminal['attempts'] > 0,
                'Owned failure probe terminal authority, phase or attempt differs',
            );
            $nativeFailure = $terminal['nativeFailure'] ?? null;
            $owned = $terminal['ownedTerminal'] ?? null;
            Reports::require(
                (is_string($nativeFailure) && $nativeFailure !== '')
                || (is_array($owned) && ($owned['state'] ?? null) === 'exited'
                    && is_int($owned['exitCode'] ?? null) && $owned['exitCode'] !== 0
                    && is_int($owned['attempt'] ?? null) && $owned['attempt'] > 0
                    && Reports::identity($owned, $baseline)),
                'Owned failure probe did not observe a terminal native failure',
            );
            $this->verify();
        });
    }

    public function checkOutcome(ProcessResult $result): void
    {
        (new CompletionPolicy([0, 137]))->complete($result);
    }

    public function terminalHeartbeatSource(): string
    {
        $before = Reports::json($this->artifacts . '/containers-before.json');
        Reports::require($before[0]['Id'] === $this->units['control']['id'], 'Terminal heartbeat source differs from the saved control container');
        $bootSettings = array_filter($before[0]['Config']['Env'], static fn(string $value): bool => str_starts_with($value, 'BAANDER_WORKER_BOOT_ID=')) |> array_values(...);
        Reports::require(count($bootSettings) === 1, 'Terminal heartbeat requires one saved boot identity');
        $boot = substr($bootSettings[0], strlen('BAANDER_WORKER_BOOT_ID='));
        Reports::require(preg_match('/\A[a-zA-Z0-9_-]+\z/D', $boot) === 1, 'Terminal heartbeat boot path is invalid');
        $mounts = array_filter($before[0]['Mounts'], static fn(array $mount): bool => $mount['Type'] === 'volume' && $mount['Destination'] === '/run/baander-worker') |> array_values(...);
        Reports::require(count($mounts) === 1 && is_string($mounts[0]['Name'] ?? null) && $mounts[0]['Name'] !== '', 'Terminal heartbeat requires the saved IPC volume');
        Reports::require(array_any($before[1]['Mounts'], static fn(array $mount): bool => $mount['Type'] === 'volume'
            && $mount['Destination'] === $mounts[0]['Destination'] && ($mount['Name'] ?? null) === $mounts[0]['Name']), 'Worker pair does not share the saved IPC volume');
        return $this->units['control']['id'] . ':' . $mounts[0]['Destination'] . '/' . $boot . '/control.json';
    }

    /** @param callable():void $evidence */
    private function completeProbe(ProcessResult $result, callable $evidence): void
    {
        $this->checkOutcome($result);
        try {
            $evidence();
        } catch (Throwable $error) {
            if ($result->exitCode === 137) {
                throw new CommandFailure('Contained probe exited without verified terminal evidence.', 137, $result, $error);
            }
            throw $error;
        }
    }

    private function verify(): void
    {
        $before = Reports::json($this->artifacts . '/containers-before.json');
        $drained = Reports::json($this->artifacts . '/drain.json');
        foreach (['control', 'execution'] as $index => $name) {
            Reports::require(
                $before[$index]['Id'] === $this->units[$name]['id'] && $drained[$index]['Id'] === $this->units[$name]['id']
                && $drained[$index]['State']['Running'] === false,
                'Interrupted sampler lacks the exact stopped container',
            );
        }
        $samples = [];
        foreach (['control', 'execution'] as $name) {
            $rows = Reports::samples($this->artifacts . '/' . $name . '-samples.jsonl');
            Reports::require(count($rows) > 10, 'Missing contained sampler baseline');
            foreach ($rows as $index => $row) {
                Reports::require(is_int($row['monotonic'] ?? null) || is_float($row['monotonic'] ?? null), 'Invalid contained sampler clock');
                if ($index > 0) {
                    $gap = $row['monotonic'] - $rows[$index - 1]['monotonic'];
                    Reports::require($gap > 0 && $gap <= 2, 'Contained sampler continuity lost');
                }
            }
            $samples[$name] = $rows;
        }
        $control = $samples['control'][array_key_last($samples['control'])];
        $execution = $samples['execution'][array_key_last($samples['execution'])];
        Reports::require(
            Reports::identity($control['heartbeat'], $samples['control'][0]['heartbeat']),
            'Control drain belongs to a different boot or epoch',
        );
        Reports::require(is_file($this->artifacts . '/control-drain-capture.json') && is_file($this->artifacts . '/control-drain-heartbeat.json'), 'Missing persisted control drain capture');
        $capture = Reports::json($this->artifacts . '/control-drain-capture.json');
        Reports::require(($capture['containerId'] ?? null) === $this->units['control']['id']
            && ($capture['source'] ?? null) === $this->terminalHeartbeatSource()
            && ($capture['copiedAfterStop'] ?? null) === true, 'Terminal heartbeat lacks exact stopped-container capture');
        $terminal = Reports::json($this->artifacts . '/control-drain-heartbeat.json');
        Reports::require(
            count($terminal) === 7 && Reports::identity($terminal, $samples['control'][0]['heartbeat'])
            && ($terminal['unit'] ?? null) === 'control' && ($terminal['ready'] ?? null) === false && ($terminal['draining'] ?? null) === true,
            'Persisted terminal heartbeat does not prove the original control drain',
        );
        $time = $terminal['time'] ?? null;
        Reports::require(
            (is_int($time) || is_float($time)) && is_finite((float) $time)
            && $time >= $control['heartbeat']['time'] && $time >= $control['monotonic'] - .25
            && $time - $control['monotonic'] <= 2 && $time - $execution['monotonic'] <= 2,
            'Terminal heartbeat lies outside continuous sampler coverage',
        );
        Reports::require(abs($execution['monotonic'] - $control['monotonic']) <= 2, 'Execution sampler ended before contained drain');
        $exhaustion = ControlCapacityVerifier::exhausted($this->artifacts, $this->phase, true);
        if ($exhaustion['source'] === 'execution-cgroup-counter') {
            [$key, $counter] = $this->phase === 'memory' ? ['memory.events', 'oom_kill'] : ['pids.events', 'max'];
            Reports::require(
                (Reports::counters($execution[$key])[$counter] ?? 0) === $exhaustion['maximum'],
                'Execution sampler ended before exhaustion',
            );
        }
        ControlCapacityVerifier::verify($this->artifacts, $this->phase);
    }
}
