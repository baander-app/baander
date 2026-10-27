<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

use Baander\Tooling\Fixtures\Failure;
use RuntimeException;

final readonly class LifecycleCheckpoint
{
    public function __construct(private OperatorQualification $qualification) {}

    private function observe(string $action, string $label, int $epoch, ?string $input = null, bool $check = true): bool
    {
        $q = $this->qualification;
        $s = $q->state;
        $result = $q->docker(
            [
                'run',
                '--name',
                $s['runId'] . '-client',
                '--network',
                $s['runId'],
                '--env-file',
                $q->work('runtime.env'),
                '--cap-drop',
                'ALL',
                '--security-opt',
                'no-new-privileges',
                '--memory',
                '256m',
                '--pids-limit',
                '32',
                '-i',
                '--entrypoint',
                '/usr/local/bin/php',
                $s['imageId'],
                '-d',
                'memory_limit=128M',
                'tests/Fixtures/Worker/lifecycle-check.php',
                $action,
                $s['namespace'],
                $s['boot'],
                (string) $epoch,
                '/tmp/lifecycle-receipt.json',
            ],
            false,
            input: $input === null ? '' : $q->read($input),
            log: $q->artifact($label . '.log'),
        );
        $copy = $q->docker(['cp', $s['runId'] . '-client:/tmp/lifecycle-receipt.json', $q->artifact($label . '.json')], false);
        $remove = $q->docker(['rm', '-f', $s['runId'] . '-client'], false);
        $passed = $result->exitCode === 0 && $copy->exitCode === 0 && $remove->exitCode === 0;
        if ($check && !$passed) {
            throw new Failure(
                'Lifecycle observation failed: ' . $label,
                $copy->exitCode !== 0 || $remove->exitCode !== 0 ? 1 : $result->exitCode,
            );
        }
        return $passed;
    }

    private function completed(string $seed, string $label): void
    {
        $q = $this->qualification;
        $deadline = OperatorCommands::now() + 90;
        do {
            if ($this->observe('completed', $label, 2, $q->artifact($seed . '.json'), false)) {
                return;
            }
            $q->commands->pause(0.5);
        } while (OperatorCommands::now() < $deadline);
        throw new RuntimeException('Lifecycle work did not complete: ' . $q->artifact($label . '.json'));
    }

    private function retired(string $label, string $control, string $execution): void
    {
        $q = $this->qualification;
        $q->operator('recover', 0);
        $q->copy($q->work('recover.json'), $q->artifact($label . '-recover.json'));
        $q->operator('recover', 0);
        $q->operator('status', 0);
        $q->copy($q->work('status.json'), $q->artifact($label . '-status.json'));
        $q->assertRetired();
        $result = $q->result();
        foreach (['control' => $control, 'execution' => $execution] as $role => $identity) {
            $q->expect(
                $result['units'][$role]['containerId'] === $identity && $result['units'][$role]['removed'],
                'Retired immutable identity changed',
            );
        }
        $q->docker(
            ['container', 'ls', '--all', '--no-trunc', '--format', '{{.ID}}'],
            stdout: $q->artifact($label . '-containers.txt'),
        );
        $present = explode("\n", $q->read($q->artifact($label . '-containers.txt')));
        $q->expect(
            !in_array($control, $present, true) && !in_array($execution, $present, true),
            'Retired immutable unit still exists',
        );
    }

    private static function redisIdentity(string $contents): string
    {
        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, 'run_id:')) {
                return trim(substr($line, strlen('run_id:')));
            }
        }
        throw new RuntimeException('Redis server identity missing');
    }

    public function run(): void
    {
        $q = $this->qualification;
        $s = &$q->state;
        $phase = $s['lifecyclePhase'];
        $started = OperatorCommands::now();
        $predecessor = $s['boot'];
        $q->copy($q->work('manifest.json'), $q->artifact('manifest.json'));
        $q->docker(
            ['inspect', $s['controlName'], $s['workerName']],
            stdout: $q->artifact('predecessor-containers.json'),
        );
        $oldControl = $q->output(['inspect', '--format', '{{.Id}}', $s['controlName']]);
        $oldExecution = $q->output(['inspect', '--format', '{{.Id}}', $s['workerName']]);
        $q->docker(
            ['container', 'ls', '--all', '--format', '{{.Names}}'],
            stdout: $q->artifact('containers-before.txt'),
        );
        $manifest = OperatorConfiguration::json($q->artifact('manifest.json'));
        $q->expect(
            $manifest['version'] === 5 && $manifest['autoscaling']['maximumConcurrency'] === 2
            && $manifest['controlMemoryMiB'] === 2944
            && $manifest['controlPidsLimit'] === 129
            && $manifest['executionMemoryMiB'] === 4800
            && $manifest['executionPidsLimit'] === 257,
            'Lifecycle requires the two-slot bounded worker pair',
        );
        $q->expect(
            !in_array($s['webName'], explode("\n", $q->read($q->artifact('containers-before.txt'))), true),
            'HTTP must be absent',
        );
        $this->observe('lease', 'predecessor-lease', 1);
        if ($phase === 'redis') {
            $q->docker(
                [
                    'exec',
                    '-e',
                    'REDISCLI_AUTH=test-only',
                    $s['runId'] . '-redis',
                    'redis-cli',
                    '--raw',
                    'INFO',
                    'server',
                ],
                stdout: $q->artifact('redis-before.txt'),
            );
        }
        $s['faultContainer'] = $q->output(['inspect', '--format', '{{.Id}}', $s['runId'] . '-' . $phase]);
        $q->docker(['pause', $s['faultContainer']]);
        $faultStarted = OperatorCommands::now();
        $q->expect(
            $q->output(['inspect', '--format', '{{.State.Paused}}', $s['faultContainer']]) === 'true',
            'Dependency did not pause',
        );
        $stopStarted = $faultStarted;
        if ($phase === 'redis') {
            $deadline = OperatorCommands::now() + 15;
            $observed = false;
            do {
                $q->operator('status', 0);
                $status = $q->result()['autoscaling'];
                if (($status['authorityValid'] ?? false) && ($status['controllerFresh'] ?? false)
                    && ($status['evidence']['queueState'] ?? null) === 'unavailable'
                    && ($status['reason'] ?? null) === 'measurements_unavailable'
                    && ($status['desired'] ?? -1) >= 0
                    && $status['desired'] <= 1) {
                    $observed = true;
                    break;
                }
                $q->commands->pause(0.2);
            } while (OperatorCommands::now() < $deadline);
            $q->expect($observed, 'Redis failure was not independently observable');
            $q->copy($q->work('status.json'), $q->artifact('redis-unavailable.json'));
            $this->observe('seed', 'retained-work', 1);
            $this->observe('pending', 'pending-before-stop', 1, $q->artifact('retained-work.json'));
            $stopStarted = OperatorCommands::now();
            $q->docker(['kill', '--signal', 'TERM', $oldControl]);
        }
        $stopped = false;
        do {
            $q->docker(['inspect', $oldControl, $oldExecution], stdout: $q->artifact('draining-containers.json'));
            $units = OperatorConfiguration::json($q->artifact('draining-containers.json'));
            if (count($units) === 2
                && array_all($units, static fn(array $unit): bool => !$unit['State']['Running'] && $unit['State']['Pid'] === 0)) {
                $stopped = true;
                break;
            }
            $q->commands->pause(0.2);
        } while (OperatorCommands::now() < $stopStarted + 50);
        $q->expect($stopped, 'Pair did not stop within the lifecycle deadline');
        $drainSeconds = (int) floor(OperatorCommands::now() - $stopStarted);
        $q->expect(
            array_column($units, 'Id') === [$oldControl, $oldExecution]
            && array_all($units, static fn(array $unit): bool => !$unit['State']['OOMKilled'] && $unit['RestartCount'] === 0),
            'Stopped identities changed or OOM/restart occurred',
        );
        if ($phase === 'postgres') {
            $q->expect(
                array_any($units, static fn(array $unit): bool => $unit['State']['ExitCode'] !== 0),
                'Authority loss must be a failed runtime',
            );
        }
        foreach ([$oldControl, $oldExecution] as $unit) {
            $q->expect($q->docker(['exec', $unit, 'true'], false)->exitCode !== 0, 'Stopped namespace accepts processes');
        }
        $q->docker(['logs', $oldControl], log: $q->artifact('predecessor-control.log'));
        $q->docker(['logs', $oldExecution], log: $q->artifact('predecessor-execution.log'));
        $q->docker(['unpause', $s['faultContainer']]);
        $s['faultContainer'] = '';
        if ($phase === 'redis') {
            $q->docker(
                [
                    'exec',
                    '-e',
                    'REDISCLI_AUTH=test-only',
                    $s['runId'] . '-redis',
                    'redis-cli',
                    '--raw',
                    'INFO',
                    'server',
                ],
                stdout: $q->artifact('redis-after.txt'),
            );
            $q->expect(
                self::redisIdentity($q->read($q->artifact('redis-before.txt'))) === self::redisIdentity($q->read($q->artifact('redis-after.txt'))),
                'Redis restarted during the preserved-state fault',
            );
            $this->observe('pending', 'pending-after-stop', 1, $q->artifact('retained-work.json'));
        }
        $this->observe('lease', 'reserved-lease', 1);
        $q->operator('status', 0);
        $q->copy($q->work('status.json'), $q->artifact('reserved-status.json'));
        $reserved = $q->result();
        $q->expect(
            $reserved['reservation'] === 'this_boot' && $reserved['retirement'] === 'none',
            'Stopped pair lost its reservation',
        );
        foreach (['control' => $oldControl, 'execution' => $oldExecution] as $role => $identity) {
            $q->expect(
                $reserved['units'][$role]['containerId'] === $identity && !$reserved['units'][$role]['removed'],
                'Stopped pair ownership changed',
            );
        }
        $successor = bin2hex(random_bytes(16));
        $q->boot($successor);
        $q->operator('create', 3);
        $q->copy($q->work('create.json'), $q->artifact('premature-create.json'));
        $q->expect(
            $q->result('create')['state'] === 'reservation_denied',
            'Successor bypassed predecessor reservation',
        );
        $q->boot($predecessor);
        $this->retired('predecessor', $oldControl, $oldExecution);
        $q->boot($successor, true);
        $q->operator('create', 0);
        $q->operator('start', 0);
        $q->awaitCheck('ready-recovered');
        $q->awaitCheck('ready-recovered', $s['workerName']);
        $this->observe('lease', 'successor-lease', 2);
        if ($phase === 'postgres') {
            $this->observe('seed', 'retained-work', 2);
        }
        $this->completed('retained-work', 'completed-work');
        OperatorConfiguration::write(
            $q->work('stale.json'),
            [
                'predecessor' => ['namespace' => $s['namespace'], 'bootId' => $predecessor, 'epoch' => 1],
                'successor' => ['namespace' => $s['namespace'], 'bootId' => $successor, 'epoch' => 2],
            ],
        );
        $this->observe('stale-lease', 'stale-renewal', 2, $q->work('stale.json'));
        $q->boot($predecessor);
        $q->operator('recover', 0);
        $q->copy($q->work('recover.json'), $q->artifact('delayed-predecessor-recovery.json'));
        $q->operator('start', 3);
        $q->boot($successor);
        $this->observe('lease', 'successor-after-stale', 2);
        $this->observe('seed', 'subsequent-work', 2);
        $this->completed('subsequent-work', 'completed-subsequent-work');
        $q->docker(
            ['inspect', $s['controlName'], $s['workerName']],
            stdout: $q->artifact('successor-containers.json'),
        );
        $units = OperatorConfiguration::json($q->artifact('successor-containers.json'));
        $q->expect(
            count($units) === 2
            && array_all(
                $units,
                static fn(array $unit): bool => $unit['State']['Running'] && $unit['RestartCount'] === 0 && !in_array($unit['Id'], [$oldControl, $oldExecution], true),
            ),
            'Successor pair is not a fresh running pair',
        );
        $this->retired(
            'successor',
            $q->output(['inspect', '--format', '{{.Id}}', $s['controlName']]),
            $q->output(['inspect', '--format', '{{.Id}}', $s['workerName']]),
        );
        OperatorConfiguration::write(
            $q->artifact('qualification.json'),
            [
                'success' => true,
                'phase' => $phase,
                'drainSeconds' => $drainSeconds,
                'seconds' => (int) floor(OperatorCommands::now() - $started),
                'drainTrigger' => $phase === 'redis' ? 'explicit-pid1-term' : 'automatic-authority-loss',
                'maximumConcurrency' => 2,
                'httpAbsent' => true,
                'predecessorEpoch' => 1,
                'successorEpoch' => 2,
            ],
        );
        echo 'PASS: ' . $phase . " outage retained ownership, drained the pair and recovered exact work under a successor without HTTP.\n";
    }
}
