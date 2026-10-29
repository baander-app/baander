<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Worker;

final readonly class OperatorScenario
{
    public function __construct(private OperatorQualification $qualification) {}

    private function initialChecks(): void
    {
        $s = $this->qualification->state;
        if ($this->qualification->configuration->twoCommand) {
            $this->qualification->provisioning->startWeb();
            $this->qualification->docker(
                ['exec', $s->webName, 'cat', $this->qualification->application('%APP_HOME%/storage/two-command/state.json')],
                stdout: $this->qualification->artifact('catalog.json'),
            );
            if ($this->qualification->configuration->movieEndpoint) {
                mkdir($this->qualification->artifact('source'), 0700);
                foreach (['endpoint-source.json', 'endpoint-source-probe.json'] as $file) {
                    $this->qualification->docker([
                        'cp',
                        $s->webName . $this->qualification->application(':%APP_HOME%/storage/two-command/') . $file,
                        $this->qualification->artifact('source/' . $file),
                    ]);
                }
            }
            $catalog = OperatorConfiguration::json($this->qualification->artifact('catalog.json'));
            $login = [
                'email' => 'two-command-admin@baander.app',
                'password' => 'Two-command-drill-only-123!',
                'moviePublicId' => $catalog['movie'],
                'videoPublicId' => $catalog['video'],
                'expectedSeekPosition' => 30,
            ];
            if ($this->qualification->configuration->movieEndpoint) {
                $this->qualification->expect($catalog['scenario'] === 'movie-endpoint', 'Wrong movie endpoint scenario');
                $login['scenario'] = $catalog['scenario'];
                $login['endpoint'] = $catalog['endpoint'];
            }
            $this->qualification->expect(!file_exists($this->qualification->artifact('browser.json')), 'Browser credentials already exist');
            OperatorConfiguration::write($this->qualification->artifact('browser.json'), $login);
            $this->qualification->checkpoint('authenticated');
            if (!$this->qualification->configuration->movieEndpoint && !$this->capacityCheckpoint()) {
                $this->qualification->combinedPhase('absent');
                $this->qualification->snapshot('web', $s->webName, 'web-without-worker');
            }
        }
        $this->qualification->operator('create', 0);
        $this->assertStopped();
        $this->qualification->operator('reconcile-create', 0);
        $this->assertStopped();
        $this->qualification->operator('status', 0);
        $result = $this->qualification->result();
        $this->qualification->expect(
            $result['manifestMatches'] === true && count($result['units']) === 2
            && isset($result['units']['control'], $result['units']['execution'])
            && array_all(
                $result['units'],
                static fn(array $unit): bool => is_string($unit['containerId']) && $unit['containerId'] !== '' && !$unit['startClaimed'],
            )
            && $result['retirement'] === 'none'
            && $result['reservation'] === 'none'
            && $result['readiness'] === 'not_checked'
            && $result['autoscaling'] === ['state' => 'unavailable']
            && $result['namespace'] === $s->namespace
            && $result['bootId'] === $s->boot,
            'Unexpected newly created operator status',
        );
        $this->qualification->operator('readiness', 1);
        $manifest = OperatorConfiguration::json($this->qualification->work('manifest.json'));
        $drift = $manifest;
        if (isset($drift['mediaMounts'][0]['source'])) {
            $drift['mediaMounts'][0]['source'] = '/tmp';
        } else {
            $drift['mediaMounts'][0]['volume'] .= '-drift';
        }
        OperatorConfiguration::write($this->qualification->work('manifest.json'), $drift);
        $this->qualification->publishManifest();
        $this->qualification->operator('status', 0);
        $this->qualification->expect($this->qualification->result()['manifestMatches'] === false, 'Changed source manifest was accepted');
        $this->qualification->operator('start', 3);
        $this->assertStopped();
        OperatorConfiguration::write($this->qualification->work('manifest.json'), $manifest);
        $this->qualification->publishManifest();
        $this->qualification->operator('start', 0);
        $this->qualification->operator('start', 3);
        $this->qualification->awaitCheck('ready');
        $this->qualification->awaitCheck('ready', $s->workerName);
        $this->qualification->operator('readiness', 0);
        $this->qualification->expect($this->qualification->result('readiness')['readiness'] === 'ready', 'Worker did not become ready');
        if ($this->qualification->configuration->developmentSource) {
            $this->qualification->expect(
                $this->qualification->output(['exec', $s->workerName, 'cat', '/media/source/receipt.txt']) === 'baander-source-readonly',
                'Development source receipt changed',
            );
            $this->qualification->expect(
                $this->qualification->docker(
                    ['exec', $s->workerName, 'sh', '-c', 'echo forbidden > /media/source/baander-readonly-probe'],
                    false,
                )->exitCode !== 0,
                'Admitted development source allowed a write',
            );
            $this->qualification->docker(['inspect', $s->workerName], stdout: $this->qualification->work('dev-inspect.json'));
            $worker = OperatorConfiguration::json($this->qualification->work('dev-inspect.json'))[0];
            $source = array_find($worker['Mounts'], static fn(array $mount): bool => $mount['Destination'] === '/media/source');
            $this->qualification->expect(
                $source !== null && $source['Type'] === 'bind' && $source['Source'] === $s->developmentSourcePath && !$source['RW'],
                'Development source mount recipe changed',
            );
            $this->qualification->expect(
                array_all(
                    $worker['Mounts'],
                    static fn(array $mount): bool => $mount === $source || ($mount['Type'] === 'volume' && $mount['Driver'] === 'local'),
                ),
                'Unexpected nonlocal development mount',
            );
        }
        $this->qualification->awaitCheck('verify-outbox');
        $this->qualification->awaitCheck('verify-scheduler');
        $this->qualification->operator('status', 0);
        $autoscaling = $this->qualification->result()['autoscaling'];
        $maximum = $manifest['autoscaling']['maximumConcurrency'];
        $this->qualification->expect(
            $autoscaling['state'] === 'known' && $autoscaling['authorityValid'] && $autoscaling['controllerFresh']
            && 0 <= $autoscaling['desired']
            && $autoscaling['desired'] <= $maximum
            && $autoscaling['occupied'] <= $maximum
            && $autoscaling['resourceFresh']
            && $autoscaling['resources']['state'] === 'known'
            && $autoscaling['evidence']['queueState'] === 'known',
            'Autoscaling evidence is not current and bounded',
        );
    }

    private function assertStopped(): void
    {
        foreach (['workerName', 'controlName'] as $role) {
            $this->qualification->expect(
                $this->qualification->output(['inspect', '--format', '{{.State.Status}} {{.State.Pid}}', $this->qualification->state->{$role}]) === 'created 0',
                'Creation started a worker before admission',
            );
        }
    }

    private function capacityCheckpoint(): string
    {
        return $this->qualification->configuration->checkpoint === null ? '' : $this->qualification->configuration->checkpoint->path;
    }

    public function assertRetired(bool $claimed = false): void
    {
        $result = $this->qualification->result();
        $this->qualification->expect(
            $result['manifestMatches'] === true && $result['retirement'] === 'completed' && $result['reservation'] === 'none'
            && count($result['units']) === 2
            && isset($result['units']['control'], $result['units']['execution'])
            && array_all(
                $result['units'],
                static fn(array $unit): bool => $unit['removed'] && (!$claimed || $unit['startClaimed']),
            ),
            'Exact worker pair was not retired',
        );
    }

    private function attachedChecks(): void
    {
        $s = $this->qualification->state;
        $this->qualification->combinedPhase('attach');
        $this->qualification->snapshot('web', $s->webName, 'web-attached');
        $this->qualification->snapshot('worker', $s->workerName, 'worker-attached');
        $snapshot = OperatorConfiguration::json($this->qualification->artifact('worker-attached-processes.json'));
        $video = array_filter(
            $snapshot['encoders'],
            static fn(array $process): bool => str_ends_with($process['arguments'][0], 'ffmpeg') && in_array('/media/source/video.mkv', $process['arguments'], true),
        );
        $this->qualification->expect(count($video) === 1, 'Concurrent attachments must have exactly one actual video encoder');
        $this->qualification->checkpoint('attached');
        if ($this->qualification->configuration->browserControls) {
            $this->qualification->checkpoint('client-controls');
        }
        $this->qualification->combinedPhase('seek-paused');
        $this->qualification->snapshot('worker', $s->workerName, 'worker-paused');
        $this->qualification->checkpoint('sought-paused');
        $this->qualification->docker(['stop', '--time', '35', $s->webName]);
        $this->qualification->docker(['rm', $s->webName]);
        $this->qualification->provisioning->startWeb();
        $this->qualification->snapshot('worker', $s->workerName, 'worker-after-web-restart');
        $identities = static function (array $snapshot): array {
            $result = [];
            foreach ($snapshot['encoders'] as $process) {
                if (str_ends_with($process['arguments'][0], 'ffmpeg')) {
                    $result[] = $process['pid'] . ':' . $process['startTicks'];
                }
            }
            return array_unique($result);
        };
        $before = $identities(OperatorConfiguration::json($this->qualification->artifact('worker-paused-processes.json')));
        $after = $identities(OperatorConfiguration::json($this->qualification->artifact('worker-after-web-restart-processes.json')));
        $this->qualification->expect(
            $before !== [] && array_diff($before, $after) === [],
            'Replacing web stopped or replaced its independent encoder',
        );
        $this->qualification->snapshot('web', $s->webName, 'web-restarted');
        $this->qualification->checkpoint('web-restarted');
        $this->qualification->combinedPhase('resume');
        $this->qualification->snapshot('worker', $s->workerName, 'worker-before-retirement');
        $this->qualification->docker(
            ['top', $s->workerName, '-eo', 'pid,ppid,args'],
            stdout: $this->qualification->artifact('worker-host-pids.txt'),
        );
        $this->qualification->hostIdentities(
            'capture',
            $this->qualification->artifact('worker-host-pids.txt'),
            $this->qualification->artifact('worker-host-identities.json'),
        );
        $this->qualification->docker(['inspect', $s->workerName], stdout: $this->qualification->artifact('worker-inspect.json'));
        $worker = OperatorConfiguration::json($this->qualification->artifact('worker-inspect.json'))[0];
        $source = array_find($worker['Mounts'], static fn(array $mount): bool => $mount['Destination'] === '/media/source');
        $this->qualification->expect(
            $worker['Image'] === $s->imageId && count($worker['Mounts']) === 4 && !$worker['HostConfig']['Privileged']
            && array_all(
                $worker['Mounts'],
                static fn(array $mount): bool => $mount['Type'] === 'volume' && !str_contains($mount['Destination'], 'docker.sock'),
            )
            && array_any($worker['Mounts'], static fn(array $mount): bool => $mount['Destination'] === '/run/baander-worker')
            && $source !== null
            && !$source['RW']
            && array_all($worker['Mounts'], static fn(array $mount): bool => $mount === $source || $mount['RW']),
            'Admitted worker recipe changed',
        );
        $this->qualification->docker(['logs', $s->workerName], log: $this->qualification->artifact('worker-before-retirement.log'));
    }

    public function finalDrill(): void
    {
        $s = $this->qualification->state;
        $this->qualification->docker(
            ['top', $s->workerName, '-eo', 'pid,ppid,args'],
            stdout: $this->qualification->artifact('recovered-host-pids.txt'),
        );
        $this->qualification->hostIdentities(
            'capture',
            $this->qualification->artifact('recovered-host-pids.txt'),
            $this->qualification->artifact('recovered-host-identities.json'),
        );
        $this->qualification->combinedPhase('cancel');
        $this->qualification->docker(['stop', '--time', '5', $s->runId . '-redis']);
        $this->qualification->combinedPhase('dependency');
        $this->qualification->snapshot('web', $s->webName, 'web-queue-outage');
        $this->qualification->docker(['start', $s->runId . '-redis']);
        $this->qualification->docker(['logs', $s->workerName], log: $this->qualification->artifact('worker-before-final-retirement.log'));
        $this->qualification->operator('recover', 0);
        $this->qualification->hostIdentities(
            'verify',
            $this->qualification->artifact('recovered-host-identities.json'),
            $this->qualification->artifact('final-containment.json'),
        );
        $this->qualification->expect(
            $this->qualification->output(['container', 'ls', '--all', '--filter', 'name=^/' . $s->workerName . '$', '--format', '{{.ID}}']) === '',
            'Recovered worker survived retirement',
        );
        if ($this->qualification->configuration->mediaObservation) {
            $this->qualification->docker(
                ['exec', $s->webName, 'php', 'tests/Fixtures/Worker/MediaControlObservation.php', 'verify'],
                stdout: $this->qualification->artifact('native-launch-summary.json'),
            );
        }
        $this->qualification->checkpoint('complete');
        $phases = [];
        foreach (glob($this->qualification->artifact('*.json')) ?: [] as $path) {
            $value = json_decode($this->qualification->read($path), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($value) && isset($value['phase'])) {
                $phases[] = ['phase' => $value['phase'], 'seconds' => $value['seconds']];
            }
        }
        $entries = iterator_to_array(new \FilesystemIterator($s->artifacts));
        $bytes = 0;
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
            }
        }
        OperatorConfiguration::write(
            $this->qualification->artifact('summary.json'),
            ['phases' => $phases, 'artifactCount' => count($entries), 'artifactBytes' => $bytes],
        );
        echo "PASS: production web and admitted worker delivered decoded media, converged attachments, persisted controls, survived web replacement, recovered after containment and retired without surviving containers.\n";
    }

    public function run(): void
    {
        $this->qualification->provisioning->setup();
        $this->initialChecks();
        $s = $this->qualification->state;
        if ($this->qualification->configuration->lifecyclePhase !== '') {
            new LifecycleCheckpoint($this->qualification)->run();
            return;
        }
        if ($this->qualification->configuration->movieEndpoint) {
            $this->qualification->checkpoint('movie-endpoint');
            $this->qualification->snapshot('worker', $s->workerName, 'endpoint-worker-before-retirement');
            $this->qualification->docker(
                ['top', $s->workerName, '-eo', 'pid,ppid,args'],
                stdout: $this->qualification->artifact('endpoint-worker-host-pids.txt'),
            );
            $this->qualification->hostIdentities(
                'capture',
                $this->qualification->artifact('endpoint-worker-host-pids.txt'),
                $this->qualification->artifact('endpoint-host-identities.json'),
            );
            $this->qualification->checkpoint('endpoint-complete');
            $this->qualification->operator('recover', 0);
            $this->qualification->operator('recover', 0);
            $this->qualification->operator('status', 0);
            $this->assertRetired();
            $this->qualification->copy($this->qualification->work('status.json'), $this->qualification->artifact('endpoint-retirement.json'));
            $this->qualification->hostIdentities(
                'verify',
                $this->qualification->artifact('endpoint-host-identities.json'),
                $this->qualification->artifact('endpoint-containment.json'),
            );
            echo "PASS: movie endpoint playback completed and both worker units retired.\n";
            return;
        }
        $checkpoint = $this->capacityCheckpoint();
        if ($checkpoint !== '') {
            $this->qualification->commands->run(
                [
                    $checkpoint,
                    $s->artifacts,
                    $s->controlName,
                    $s->workerName,
                    $s->ipcVolume,
                    $s->runId . '-postgres',
                    'worker_operator_test',
                ],
                terminationGrace: 40,
            );
            $this->qualification->operator('recover', 0);
            $this->qualification->operator('recover', 0);
            $this->qualification->operator('status', 0);
            $this->assertRetired();
            $this->qualification->copy($this->qualification->work('status.json'), $this->qualification->artifact('capacity-retirement.json'));
            echo "PASS: capacity checkpoint completed and both immutable worker units retired.\n";
            return;
        }
        $this->qualification->operator('status', 0);
        $result = $this->qualification->result();
        $this->qualification->expect(
            $result['manifestMatches'] === true && $result['retirement'] === 'none' && $result['reservation'] === 'this_boot'
            && $result['readiness'] === 'not_checked'
            && array_all(
                $result['units'],
                static fn(array $unit): bool => $unit['startClaimed'] && is_string($unit['containerId']) && strlen($unit['containerId']) === 64,
            ),
            'Started operator status is inconsistent',
        );
        if ($this->qualification->configuration->twoCommand) {
            $this->attachedChecks();
        }
        $this->qualification->operator('recover', 0);
        $this->qualification->operator('recover', 0);
        $this->qualification->operator('readiness', 1);
        $this->qualification->operator('status', 0);
        $this->assertRetired(true);
        $this->qualification->expect($this->qualification->result()['readiness'] === 'not_checked', 'Retired readiness changed');
        $this->qualification->operator('create', 3);
        $this->qualification->operator('start', 3);
        foreach ([$s->controlName, $s->workerName] as $name) {
            $this->qualification->expect(
                $this->qualification->output([
                    'container',
                    'ls',
                    '--all',
                    '--no-trunc',
                    '--filter',
                    'name=^/' . $name . '$',
                    '--format',
                    '{{.ID}}',
                ]) === '',
                'Retired unit still exists',
            );
        }
        if ($this->qualification->configuration->twoCommand) {
            $this->qualification->hostIdentities(
                'verify',
                $this->qualification->artifact('worker-host-identities.json'),
                $this->qualification->artifact('containment.json'),
            );
            $this->qualification->snapshot('web', $s->webName, 'web-worker-contained');
            $this->qualification->checkpoint('worker-contained');
            $this->qualification->combinedPhase('pause-absent');
            $this->qualification->boot(bin2hex(random_bytes(16)), true);
            $this->qualification->operator('create', 0);
            $this->qualification->operator('start', 0);
            $this->qualification->awaitCheck('ready-recovered');
            $this->qualification->awaitCheck('ready-recovered', $s->workerName);
            $this->qualification->combinedPhase('recovered');
            $this->qualification->snapshot('worker', $s->workerName, 'worker-recovered');
            $this->qualification->snapshot('web', $s->webName, 'web-worker-recovered');
            $this->qualification->checkpoint('worker-recovered');
            $this->finalDrill();
        }
        echo "PASS: trusted operator created and reconciled both stopped immutable units, started real application roles, processed outbox/scheduler effects, and retired the exact deployment without same-boot reuse.\n";
    }

}
