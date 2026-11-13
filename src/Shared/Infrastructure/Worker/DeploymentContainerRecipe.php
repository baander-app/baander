<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\DeploymentUnit;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Immutable host-authorized execution and shared-media topology. */
#[Exclude]
final readonly class DeploymentContainerRecipe
{
    /** NVENC needs the video capability; compute and utility load the CUDA and management libraries. */
    private const array NVIDIA_CAPABILITIES = ['compute', 'utility', 'video'];

    /** @var list<string> */
    public array $command;

    /** @var list<array{volume?:string,source?:string,target:string,readOnly:bool,role:string}> */
    public array $mediaMounts;

    /** Out-of-memory score the container starts with; it carries no CPU, memory or PID limit. */
    public int $oomScoreAdj;

    /** @var list<string> Hardware encoder device nodes the execution unit receives at the same path. */
    public array $devices;

    /** @var list<int> Supplementary groups that open those devices. */
    public array $groups;

    /** Whether the execution unit receives the host's NVIDIA GPUs through the container runtime. */
    public bool $nvidiaGpus;

    /**
     * @param array<array-key, mixed> $command Validated argv, never a shell command.
     * @param array<array-key, mixed> $mediaMounts
     * @param ?int $deviceGroup Group owning the profile's hardware encoder device on the Docker host.
     */
    public function __construct(
        public string $namespace,
        public string $bootId,
        public string $daemonId,
        public string $imageId,
        array $command,
        public string $network,
        public DeploymentUnit $unit,
        public string $ipcVolume,
        public string $appHome,
        public DeploymentProfile $deploymentProfile,
        public DeploymentRuntimeEnvironment $runtimeEnvironment = new DeploymentRuntimeEnvironment(),
        array $mediaMounts = [],
        public string $sourceMountPolicy = 'production',
        ?int $deviceGroup = null,
    ) {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if (strlen($appHome) > 4096 || preg_match('~\A/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z~D', $appHome) !== 1
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $appHome) !== 0) {
            throw new InvalidArgumentException('Observed application home requires a canonical absolute path.');
        }
        $this->oomScoreAdj = OutOfMemoryKillOrder::unit($unit);
        // Only execution runs FFmpeg, so only execution receives the hardware encoder.
        $hardware = $unit === DeploymentUnit::Execution ? $deploymentProfile->hardware : null;
        if ($hardware?->device !== null && ($deviceGroup === null || $deviceGroup < 0 || $deviceGroup > 4_294_967_294)) {
            throw new InvalidArgumentException('The hardware encoder device requires the numeric group that owns it on the Docker host.');
        }
        $this->devices = $hardware?->device === null ? [] : [$hardware->device];
        $this->groups = $hardware?->device === null || $deviceGroup === null ? [] : [$deviceGroup];
        $this->nvidiaGpus = $hardware !== null && !$hardware->usesDevice();
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $ipcVolume) !== 1) {
            throw new InvalidArgumentException('Worker IPC requires an explicitly named private volume.');
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $daemonId) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $imageId) !== 1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $network) !== 1
            || in_array(strtolower($network), ['host', 'container'], true)
        ) {
            throw new InvalidArgumentException('Deployment recipe requires bounded identity, immutable image and private network.');
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
            'imageId' => $this->imageId, 'appHome' => $this->appHome, 'command' => $this->command, 'network' => $this->network,
            'oomScoreAdj' => $this->oomScoreAdj, 'unit' => $this->unit->value, 'ipcVolume' => $this->ipcVolume,
            'sourceMountPolicy' => $this->sourceMountPolicy, 'deploymentProfile' => $this->deploymentProfile->toJson(),
            'runtimeEnvironment' => $this->runtimeEnvironment->variables, 'mediaMounts' => $this->mediaMounts,
            'devices' => $this->devices, 'groups' => $this->groups, 'nvidiaGpus' => $this->nvidiaGpus,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Docker reports an unset limit as zero, an empty CPU set and a null or zero PID limit.
     *
     * @param array<array-key, mixed> $inspection
     */
    public function matchesResources(array $inspection): bool
    {
        foreach (['memory', 'memorySwap', 'nanoCpus', 'cpuQuota', 'cpuShares'] as $field) {
            if (($inspection[$field] ?? null) !== 0) {
                return false;
            }
        }

        return array_key_exists('pidsLimit', $inspection) && in_array($inspection['pidsLimit'], [null, 0], true)
            && ($inspection['cpuSet'] ?? null) === ''
            && ($inspection['oomScoreAdj'] ?? null) === $this->oomScoreAdj;
    }

    /**
     * Docker reports device mappings, supplementary groups and GPU requests as absent or empty
     * when none were given, groups as strings, and an all-GPU request with its capabilities plus `gpu`.
     *
     * @param array<array-key, mixed> $inspection
     */
    public function matchesDevices(array $inspection): bool
    {
        $devices = array_map(static fn (string $device): array => ['PathOnHost' => $device, 'PathInContainer' => $device, 'CgroupPermissions' => 'rwm'], $this->devices);
        $groups = array_map(strval(...), $this->groups);
        $requests = $this->nvidiaGpus
            ? [['Driver' => '', 'Count' => -1, 'DeviceIDs' => null, 'Capabilities' => [[...self::NVIDIA_CAPABILITIES, 'gpu']], 'Options' => []]]
            : [];
        foreach (['devices' => $devices, 'groupAdd' => $groups, 'deviceRequests' => $requests] as $field => $expected) {
            if (!array_key_exists($field, $inspection)
                || ($expected === [] ? !in_array($inspection[$field], [null, []], true) : self::canonical($inspection[$field]) !== self::canonical($expected))) {
                return false;
            }
        }
        return true;
    }

    /** Orders object keys so inspection output compares independently of Docker's field order. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /** @return list<string> */
    public function createArguments(?string $environmentFile = null): array
    {
        if (($this->runtimeEnvironment->variables !== [] && $environmentFile === null)
            || ($environmentFile !== null && (!str_starts_with($environmentFile, '/') || str_contains($environmentFile, "\0")))) {
            throw new InvalidArgumentException('Runtime configuration requires an absolute private environment file.');
        }
        $profileArguments = array_key_exists('BAANDER_DEPLOYMENT_PROFILE', $this->runtimeEnvironment->variables)
            ? [] : ['--env', 'BAANDER_DEPLOYMENT_PROFILE=' . $this->deploymentProfile->toJson()];

        return [
            'container', 'create', '--pull=never', '--name', RegisteredDeploymentStart::containerName($this->namespace, $this->bootId, $this->unit),
            '--network', $this->network, '--oom-score-adj', (string) $this->oomScoreAdj,
            '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--cgroupns', 'private', '--restart', 'no',
            ...$this->deviceArguments(),
            '--label', 'app.baander.worker.namespace=' . $this->namespace,
            '--label', 'app.baander.worker.boot-id=' . $this->bootId, '--label', 'app.baander.worker.role=' . $this->unit->value,
            '--env', 'BAANDER_WORKER_NAMESPACE=' . $this->namespace, '--env', 'BAANDER_WORKER_BOOT_ID=' . $this->bootId,
            '--env', 'BAANDER_WORKER_UNIT=' . $this->unit->value,
            ...$profileArguments,
            ...($environmentFile === null ? [] : ['--env-file', $environmentFile]),
            ...$this->mountArguments(),
            '--mount', 'type=volume,src=' . $this->ipcVolume . ',dst=/run/baander-worker,volume-nocopy',
            '--entrypoint', $this->command[0], $this->imageId, ...array_slice($this->command, 1),
        ];
    }

    /** @return list<string> */
    private function deviceArguments(): array
    {
        $arguments = [];
        foreach ($this->devices as $device) {
            array_push($arguments, '--device', $device . ':' . $device . ':rwm');
        }
        foreach ($this->groups as $group) {
            array_push($arguments, '--group-add', (string) $group);
        }
        if ($this->nvidiaGpus) {
            array_push($arguments, '--gpus', 'all,"capabilities=' . implode(',', self::NVIDIA_CAPABILITIES) . '"');
        }
        return $arguments;
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
                self::verifySourcePath($mount['source']);
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
            if (($target === $this->appHome || str_starts_with($this->appHome, $target . '/')
                || str_starts_with($target, $this->appHome . '/'))
                && $target !== $this->appHome . '/var/transcode_state'
                && $target !== $this->appHome . '/storage'
                && !str_starts_with($target, $this->appHome . '/storage/')) {
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
        self::verifySourcePath($source);
        clearstatcache(true, $source);
        if (!is_dir($source) || realpath($source) !== $source) {
            throw new InvalidArgumentException('Source binds require an existing canonical absolute directory without symlink aliases.');
        }
    }

    private static function verifySourcePath(string $source): void
    {
        if (strlen($source) > 4096 || preg_match('~\A/(?:[A-Za-z0-9_. -]+/)*[A-Za-z0-9_. -]+\z~D', $source) !== 1
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $source) !== 0) {
            throw new InvalidArgumentException('Source binds require a canonical absolute directory path.');
        }
    }
}
