<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Immutable host-authorized execution and shared-media topology. */
#[Exclude]
final readonly class DeploymentContainerRecipe
{
    /** @var list<string> */
    public array $command;

    /** @var list<array{volume?:string,source?:string,target:string,readOnly:bool,role:string}> */
    public array $mediaMounts;

    /** @var list<int> */
    public array $cpuSet;

    /**
     * @param array<array-key, mixed> $command Validated argv, never a shell command.
     * @param array<array-key, mixed> $mediaMounts
     * @param array<array-key, mixed> $cpuSet
     */
    public function __construct(
        public string $namespace,
        public string $bootId,
        public string $daemonId,
        public string $imageId,
        array $command,
        public string $network,
        public int $memoryBytes,
        public int $nanoCpus,
        public int $pidsLimit,
        public DeploymentUnit $unit,
        array $cpuSet,
        public string $ipcVolume,
        public DeploymentRuntimeEnvironment $runtimeEnvironment = new DeploymentRuntimeEnvironment(),
        array $mediaMounts = [],
        public string $sourceMountPolicy = 'production',
    ) {
        DeploymentLease::validateIdentity($namespace, $bootId);
        $this->cpuSet = (new DeploymentResourceBudget($memoryBytes, $nanoCpus, $pidsLimit, $cpuSet))->cpuSet;
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $ipcVolume) !== 1) {
            throw new InvalidArgumentException('Worker IPC requires an explicitly named private volume.');
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $daemonId) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $imageId) !== 1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $network) !== 1
            || in_array(strtolower($network), ['host', 'container'], true)
        ) {
            throw new InvalidArgumentException('Deployment recipe requires bounded identity, immutable image, private network and explicit resource ceilings.');
        }
        if (!array_is_list($command) || count($command) < 1 || count($command) > 64) {
            throw new InvalidArgumentException('Deployment command requires 1..64 ordered arguments.');
        }
        $bytes = 0;
        $validatedCommand = [];
        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '' || strlen($argument) > 4096 || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Deployment arguments must be nonempty bounded strings without NUL bytes.');
            }
            $bytes += strlen($argument);
            $validatedCommand[] = $argument;
        }
        if (!str_starts_with($command[0], '/') || $bytes > 16 * 1024) {
            throw new InvalidArgumentException('Deployment command requires an absolute executable and at most 16 KiB of argument bytes.');
        }
        $this->command = $validatedCommand;
        if (!in_array($sourceMountPolicy, ['production', 'local-development'], true)) {
            throw new InvalidArgumentException('Unknown source mount policy.');
        }
        $this->mediaMounts = $this->validateMounts($mediaMounts);
        if (in_array($ipcVolume, array_column($this->mediaMounts, 'volume'), true)) {
            throw new InvalidArgumentException('Worker IPC volume cannot alias media storage.');
        }
    }

    /** Stable create-intent identity; every admitted configuration field participates. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'namespace' => $this->namespace, 'bootId' => $this->bootId, 'daemonId' => $this->daemonId,
            'imageId' => $this->imageId, 'command' => $this->command, 'network' => $this->network,
            'memoryBytes' => $this->memoryBytes, 'nanoCpus' => $this->nanoCpus, 'pidsLimit' => $this->pidsLimit,
            'unit' => $this->unit->value, 'cpuSet' => $this->cpuSet, 'ipcVolume' => $this->ipcVolume,
            'sourceMountPolicy' => $this->sourceMountPolicy,
            'runtimeEnvironment' => $this->runtimeEnvironment->variables, 'mediaMounts' => $this->mediaMounts,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function createArguments(?string $environmentFile = null): array
    {
        if (($this->runtimeEnvironment->variables !== [] && $environmentFile === null)
            || ($environmentFile !== null && (!str_starts_with($environmentFile, '/') || str_contains($environmentFile, "\0")))) {
            throw new InvalidArgumentException('Runtime configuration requires an absolute private environment file.');
        }
        $wholeCpus = intdiv($this->nanoCpus, 1_000_000_000);
        $fraction = rtrim(sprintf('%09d', $this->nanoCpus % 1_000_000_000), '0');
        $cpus = (string) $wholeCpus . ($fraction === '' ? '' : '.' . $fraction);

        return [
            'container', 'create', '--pull=never', '--name', RegisteredDeploymentStart::containerName($this->namespace, $this->bootId, $this->unit),
            '--network', $this->network, '--memory', (string) $this->memoryBytes, '--memory-swap', (string) $this->memoryBytes,
            '--cpus', $cpus, '--cpuset-cpus', implode(',', $this->cpuSet), '--pids-limit', (string) $this->pidsLimit,
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--cgroupns', 'private', '--restart', 'no',
            '--label', 'app.baander.worker.namespace=' . $this->namespace,
            '--label', 'app.baander.worker.boot-id=' . $this->bootId, '--label', 'app.baander.worker.role=' . $this->unit->value,
            '--env', 'BAANDER_WORKER_NAMESPACE=' . $this->namespace, '--env', 'BAANDER_WORKER_BOOT_ID=' . $this->bootId,
            '--env', 'BAANDER_WORKER_UNIT=' . $this->unit->value,
            ...($environmentFile === null ? [] : ['--env-file', $environmentFile]),
            ...$this->mountArguments(),
            '--mount', 'type=volume,src=' . $this->ipcVolume . ',dst=/run/baander-worker,volume-nocopy',
            '--entrypoint', $this->command[0], $this->imageId, ...array_slice($this->command, 1),
        ];
    }
    /** @return list<string> */
    private function mountArguments(): array
    {
        $arguments = [];
        foreach ($this->mediaMounts as $mount) {
            $arguments[] = '--mount';
            if (isset($mount['source'])) {
                self::verifySourceDirectory($mount['source']);
                $arguments[] = 'type=bind,src=' . $mount['source'] . ',dst=' . $mount['target'] . ',readonly,bind-propagation=rprivate,bind-recursive=readonly';
            } else {
                $arguments[] = 'type=volume,src=' . $mount['volume'] . ',dst=' . $mount['target']
                    . ($mount['readOnly'] ? ',readonly' : '') . ',volume-nocopy';
            }
        }
        return $arguments;
    }

    /**
     * @param array<array-key,mixed> $mounts
     * @return list<array{volume?:string,source?:string,target:string,readOnly:bool,role:string}>
     */
    private function validateMounts(array $mounts): array
    {
        if (!array_is_list($mounts) || count($mounts) > 64) {
            throw new InvalidArgumentException('Media mounts require at most 64 ordered media declarations.');
        }
        $validated = [];
        foreach ($mounts as $mount) {
            $bind = is_array($mount) && array_key_exists('source', $mount);
            if ($bind && ($this->sourceMountPolicy !== 'local-development' || ($mount['role'] ?? null) !== 'source'
                || !is_string($mount['source']))) {
                throw new InvalidArgumentException('Only local development permits read-only source binds.');
            }
            if ($bind) {
                self::verifySourceDirectory($mount['source']);
            }
            if (!is_array($mount) || count($mount) !== 4
                || (!$bind && (!is_string($mount['volume'] ?? null)
                    || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $mount['volume']) !== 1))
                || !is_string($mount['target'] ?? null) || strlen($mount['target']) > 4096
                || preg_match('~\A/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z~D', $mount['target']) !== 1
                || preg_match('~(?:^|/)\.\.?(?:/|$)~', $mount['target']) !== 0
                || !in_array($mount['role'] ?? null, ['source', 'derived'], true)
                || !is_bool($mount['readOnly'] ?? null)
                || $mount['readOnly'] !== ($mount['role'] === 'source')
            ) {
                throw new InvalidArgumentException('Media mounts require admitted storage, canonical targets and read-only source or writable derived access.');
            }
            $target = $mount['target'];
            foreach (['/bin', '/sbin', '/usr', '/etc', '/dev', '/proc', '/sys', '/run', '/var/run', '/tmp', '/root', '/home'] as $forbidden) {
                if ($target === $forbidden || str_starts_with($target, $forbidden . '/') || str_starts_with($forbidden, $target . '/')) {
                    throw new InvalidArgumentException('Media mounts cannot expose operating-system or private worker paths.');
                }
            }
            if (($target === '/var' || $target === '/var/www' || $target === '/var/www/html'
                || str_starts_with($target, '/var/www/html/'))
                && $target !== '/var/www/html/var/transcode_state'
                && $target !== '/var/www/html/storage'
                && !str_starts_with($target, '/var/www/html/storage/')) {
                throw new InvalidArgumentException('Media mounts cannot replace application code.');
            }
            foreach ($validated as $existing) {
                if ($target === $existing['target'] || str_starts_with($target, $existing['target'] . '/')
                    || str_starts_with($existing['target'], $target . '/')
                    || (isset($mount['volume'], $existing['volume']) && $mount['volume'] === $existing['volume'] && $mount['role'] !== $existing['role'])) {
                    throw new InvalidArgumentException('Media targets cannot overlap or alias a source volume with writable access.');
                }
            }
            $validated[] = [($bind ? 'source' : 'volume') => $mount[$bind ? 'source' : 'volume'], 'target' => $target, 'readOnly' => $mount['readOnly'], 'role' => $mount['role']];
        }
        usort($validated, static fn (array $left, array $right): int => strcmp($left['target'], $right['target']));
        return $validated;
    }

    /** The trusted operator sees admitted host directories at their daemon-visible paths. */
    public static function verifySourceDirectory(string $source): void
    {
        clearstatcache(true, $source);
        if (strlen($source) > 4096 || preg_match('~\A/(?:[A-Za-z0-9_. -]+/)*[A-Za-z0-9_. -]+\z~D', $source) !== 1
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $source) !== 0 || !is_dir($source) || realpath($source) !== $source) {
            throw new InvalidArgumentException('Source binds require an existing canonical absolute directory without symlink aliases.');
        }
    }

}
