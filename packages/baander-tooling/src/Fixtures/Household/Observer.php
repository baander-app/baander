<?php

declare(strict_types=1);

namespace Baander\Tooling\Fixtures\Household;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Filesystem\{AtomicFile, PrivateDirectory};
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Process\RunningProcess;
use Baander\Tooling\Resources\{ResourceKind, ResourceLedger};

final class Observer
{
    private ?RunningProcess $process = null;
    private ?string $id = null;
    private readonly ResourceLedger $ledger;

    public function __construct(
        private readonly Client $docker,
        private readonly Runtime $runtime,
        private readonly string $directory,
        private readonly string $image,
        private readonly ApplicationLayout $layout,
        private readonly int $memoryMiB = 128,
    ) {
        PrivateDirectory::prepare($directory);
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $image) !== 1 || $memoryMiB < 64) {
            throw new \InvalidArgumentException('Observer requires an immutable image and at least 64 MiB.');
        }
        $this->ledger = new ResourceLedger($directory . '/observer-resources.json', $docker, $runtime->environment);
    }

    /** @return array<string,mixed> */
    public function start(float $deadlineSeconds): array
    {
        if ($this->process !== null || $deadlineSeconds <= 0 || !is_finite($deadlineSeconds)) {
            throw new \RuntimeException('Observer already started or deadline is invalid.');
        }
        $program = self::bootstrap($this->layout);
        $name = 'baander-household-observer-' . bin2hex(random_bytes(12));
        $this->id = $this->ledger->create(ResourceKind::Container, $name, [
            '--pid=host', '--cgroupns=host', '--network', 'none', '--read-only', '--user', '0:0',
            '--cap-drop', 'ALL', '--cap-add', 'SYS_PTRACE', '--security-opt', 'no-new-privileges', '--security-opt', 'label=disable',
            '--mount', 'type=bind,src=/proc,dst=/host/proc,readonly',
            '--mount', 'type=bind,src=/sys/fs/cgroup,dst=/host/cgroup,readonly',
            '--tmpfs', '/state:rw,noexec,nosuid,nodev,size=8m,mode=0700',
            '--memory', $this->memoryMiB . 'm', '--memory-swap', $this->memoryMiB . 'm', '--pids-limit', '32',
            '--entrypoint', 'php', $this->image, '-d', 'memory_limit=64M', '-r', $program, (string) $deadlineSeconds,
        ]);
        $this->process = $this->docker->start(
            ['start', '--attach', $this->id],
            timeout: $deadlineSeconds + 30,
            logPath: $this->directory . '/host-raw.jsonl',
            stderrLogPath: $this->directory . '/observer.stderr',
        );
        AtomicFile::writeJson($this->directory . '/observer.json', ['version' => 1, 'id' => $this->id, 'name' => $name,
            'image' => $this->image, 'memoryMiB' => $this->memoryMiB, 'proc' => '/proc', 'cgroup' => '/sys/fs/cgroup']);
        $this->runtime->await(fn(): bool => $this->isRunning(), 15, .1, 'Observer container did not start.');
        return $this->update([], 'startup');
    }

    public static function bootstrap(ApplicationLayout $layout): string
    {
        return 'require ' . var_export($layout->path('scripts/vendor/autoload.php'), true)
            . '; \\Baander\\Tooling\\Fixtures\\Household\\Observer::serve((float)$argv[1]);';
    }

    /** @param array<string,int> $roles
     * @return array{monotonic:float,epochSeconds:float}
     */
    public function update(array $roles, string $phase): array
    {
        if ($this->id === null || preg_match('/^[a-z][a-z0-9-]*$/D', $phase) !== 1) {
            throw new \RuntimeException('Observer is not started or phase is invalid.');
        }
        foreach ($roles as $role => $pid) {
            if (preg_match('/^[a-z][a-z0-9-]*$/D', $role) !== 1 || $pid < 1) {
                throw new \InvalidArgumentException('Observer roles require canonical names and positive host PIDs.');
            }
        }
        $program = '$input = stream_get_contents(STDIN); json_decode($input, true, flags: JSON_THROW_ON_ERROR); '
            . 'if(file_put_contents("/state/next.json", $input) !== strlen($input) || !rename("/state/next.json", "/state/current.json")) {exit(1);} '
            . 'echo json_encode(["monotonic"=>hrtime(true)/1e9,"epochSeconds"=>microtime(true)],JSON_THROW_ON_ERROR);';
        $result = $this->docker->run(
            ['exec', '-i', $this->id, 'php', '-d', 'memory_limit=16M', '-r', $program],
            input: json_encode(['roles' => $roles, 'phase' => $phase], JSON_THROW_ON_ERROR),
            timeout: 10,
        );
        $marker = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($marker) || !is_numeric($marker['monotonic'] ?? null) || !is_numeric($marker['epochSeconds'] ?? null)) {
            throw new \RuntimeException('Observer did not return a target-host clock marker.');
        }
        return ['monotonic' => (float) $marker['monotonic'], 'epochSeconds' => (float) $marker['epochSeconds']];
    }

    private function isRunning(): bool
    {
        if ($this->id === null) {
            return false;
        }
        return trim($this->docker->run(['inspect', '--format', '{{.State.Running}}', $this->id], check: false)->stdout) === 'true';
    }

    public function alive(): bool
    {
        return $this->process !== null && $this->runtime->alive($this->process);
    }

    /** @return array<string,mixed> */
    public function stop(): array
    {
        $normal = false;
        if ($this->id !== null && $this->process !== null && $this->alive()) {
            $this->docker->run(['exec', $this->id, 'php', '-r', 'file_put_contents("/state/stop", "stop");'], timeout: 10);
            $result = $this->runtime->wait($this->process, false);
            $normal = $result->exitCode === 0 && !$result->timedOut && !$result->cancelled;
        }
        $cleanup = $this->ledger->cleanup($this->runtime->configuration->budgets->seconds('cleanup'));
        if (!$cleanup->successful()) {
            throw new \RuntimeException('Observer cleanup remains incomplete.');
        }
        return ['normalExit' => $normal, 'resourcesRemoved' => true];
    }

    /** @param list<array<string,mixed>> $containers
     * @return array<string,int>
     */
    public static function rolesFromInspection(array $containers, string $deploymentName, string $owner): array
    {
        $roles = [];
        foreach ($containers as $container) {
            $labels = $container['Config']['Labels'] ?? [];
            $service = $labels['com.docker.compose.service'] ?? null;
            $worker = $labels['app.baander.worker.role'] ?? null;
            if (($labels['org.baander.household.owner'] ?? null) === $owner && in_array($service, ['web', 'postgres', 'redis', 'gateway'], true)) {
                $role = $service;
            } elseif (($labels['app.baander.worker.namespace'] ?? null) === $deploymentName && in_array($worker, ['control', 'execution'], true)) {
                $role = $worker;
            } else {
                continue;
            }
            if (($container['State']['Running'] ?? null) !== true || !is_int($container['State']['Pid'] ?? null) || $container['State']['Pid'] < 1) {
                continue;
            }
            if (isset($roles[$role])) {
                throw new \RuntimeException('More than one container claims observer role ' . $role . '.');
            }
            $roles[$role] = $container['State']['Pid'];
        }
        return $roles;
    }

    /** Entry point executed only inside the owned observer container. */
    public static function serve(float $deadlineSeconds): void
    {
        $sampler = new HostSampler('/host/proc', '/host/cgroup');
        $deadline = hrtime(true) / 1e9 + $deadlineSeconds;
        $phase = null;
        $sequence = 0;
        $next = hrtime(true) / 1e9;
        while (hrtime(true) / 1e9 <= $deadline && !is_file('/state/stop')) {
            $document = is_file('/state/current.json') ? json_decode(file_get_contents('/state/current.json'), true, flags: JSON_THROW_ON_ERROR) : ['roles' => [], 'phase' => 'startup'];
            if ($phase !== $document['phase']) {
                $phase = $document['phase'];
                $sequence = 0;
            }
            $roles = [];
            foreach (['observer' => getmypid(), ...$document['roles']] as $role => $pid) {
                $cgroup = @file_get_contents('/host/proc/' . $pid . '/cgroup');
                if (is_string($cgroup) && preg_match('/^0::(.+)$/m', $cgroup, $match) === 1) {
                    $roles[$role] = ['pid' => $pid, 'cgroup' => $match[1]];
                }
            }
            $sample = $sampler->capture(hrtime(true) / 1e9, $sequence++, $roles);
            $sample['epochSeconds'] = microtime(true);
            $sample['phase'] = $phase;
            $sample['requestedRoles'] = array_keys($document['roles']);
            echo json_encode($sample, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
            flush();
            $next += 1;
            usleep((int) min(1_000_000, max(0, ($next - hrtime(true) / 1e9) * 1_000_000)));
        }
    }

    public static function extractPhase(string $raw, string $destination, string $phase): void
    {
        $input = fopen($raw, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Observer output is missing.');
        }
        $output = fopen($destination, 'xb');
        if ($output === false) {
            fclose($input);
            throw new \RuntimeException('Measurement output already exists or cannot be created.');
        }
        chmod($destination, 0600);
        try {
            while (($line = fgets($input, HostSampler::MAX_LINE_BYTES + 1)) !== false) {
                $sample = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (($sample['phase'] ?? null) === $phase && fwrite($output, $line) !== strlen($line)) {
                    throw new \RuntimeException('Cannot write complete measurement sample.');
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
