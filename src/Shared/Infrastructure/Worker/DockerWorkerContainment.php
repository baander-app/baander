<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** External-controller proof: successful immutable-ID removal, never stopped-state observation. */
#[Exclude]
final readonly class DockerWorkerContainment
{
    private const string INSPECT_FORMAT = <<<'FORMAT'
{"id":{{json .Id}},"namespace":{{json (index .Config.Labels "app.baander.worker.namespace")}},"bootId":{{json (index .Config.Labels "app.baander.worker.boot-id")}},"role":{{json (index .Config.Labels "app.baander.worker.role")}},"privileged":{{json .HostConfig.Privileged}},"pidMode":{{json .HostConfig.PidMode}},"cgroupnsMode":{{json .HostConfig.CgroupnsMode}},"mounts":{{json .Mounts}},"binds":{{json (index .HostConfig "Binds")}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"devices":{{json (index .HostConfig "Devices")}},"deviceRequests":{{json (index .HostConfig "DeviceRequests")}},"capAdd":{{json (index .HostConfig "CapAdd")}},"capDrop":{{json .HostConfig.CapDrop}},"securityOpt":{{json .HostConfig.SecurityOpt}},"memory":{{json .HostConfig.Memory}},"memorySwap":{{json .HostConfig.MemorySwap}},"nanoCpus":{{json .HostConfig.NanoCpus}},"pidsLimit":{{json .HostConfig.PidsLimit}},"cpuSet":{{json .HostConfig.CpusetCpus}},"restart":{{json .HostConfig.RestartPolicy.Name}},"retryCount":{{json .HostConfig.RestartPolicy.MaximumRetryCount}}}
FORMAT;

    /** @param Closure(list<string>): string $execute Successful commands return stdout; every command failure throws. */
    public function __construct(private Closure $execute, private ?DeploymentContainerRecipe $recipe = null)
    {
    }

    /**
     * Only private PID/cgroup namespaces, admitted media and IPC mounts, no devices,
     * all capabilities dropped, and no-new-privileges are accepted.
     * The trusted controller creates one container per unit and fresh boot and
     * records its immutable ID and labels before starting it. These labels do not
     * defend against a malicious Docker operator; durable boot-to-ID inventory is
     * the caller's responsibility. Removal itself supplies proof for that known ID.
     */
    public function retire(string $containerId, DeploymentLease $lease, DeploymentUnit $unit): void
    {
        $this->verifyIsolation($containerId, $lease->namespace, $lease->bootId, $unit);
        $removed = ($this->execute)(['container', 'rm', '--force', $containerId]);
        if (trim($removed) !== $containerId) {
            throw new RuntimeException('Immutable container removal was not confirmed.');
        }
    }

    /**
     * Trusted recovery callers must first read durable retirement intent recording
     * verified isolation for this exact namespace, boot and immutable container ID.
     * The executor must be pinned to that intent's Docker daemon. Only then can a
     * successful empty listing reconcile a prior removal; absence alone is not
     * ordinary containment proof. A remaining container must still pass isolation.
     */
    public function reconcileRemoval(string $containerId, string $namespace, string $bootId, DeploymentUnit $unit): void
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if (preg_match('/\A[0-9a-f]{64}\z/D', $containerId) !== 1) {
            throw new InvalidArgumentException('Containment requires a full immutable lowercase 64-hex container ID.');
        }
        $listed = ($this->execute)([
            'container', 'ls', '--all', '--no-trunc', '--filter', 'id=' . $containerId, '--format', '{{.ID}}',
        ]);
        if (trim($listed) === '') {
            return;
        }
        if (trim($listed) !== $containerId) {
            throw new RuntimeException('Container listing did not confirm the exact immutable ID.');
        }
        $this->verifyIsolation($containerId, $namespace, $bootId, $unit);
        $removed = ($this->execute)(['container', 'rm', '--force', $containerId]);
        if (trim($removed) !== $containerId) {
            throw new RuntimeException('Immutable container removal was not confirmed.');
        }
    }

    /** Inspection verifies identity and isolation only; it supplies no retirement or start receipt. */
    public function verifyIsolation(string $containerId, string $namespace, string $bootId, DeploymentUnit $unit): void
    {
        DeploymentLease::validateIdentity($namespace, $bootId);
        if (preg_match('/\A[0-9a-f]{64}\z/D', $containerId) !== 1) {
            throw new InvalidArgumentException('Containment requires a full immutable lowercase 64-hex container ID.');
        }
        $raw = ($this->execute)(['container', 'inspect', '--format', self::INSPECT_FORMAT, $containerId]);
        try {
            $inspection = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Container inspection is not valid containment evidence.', 0, $error);
        }
        if (!is_array($inspection) || count($inspection) !== 22
            || ($inspection['id'] ?? null) !== $containerId
            || ($inspection['namespace'] ?? null) !== $namespace
            || ($inspection['bootId'] ?? null) !== $bootId
            || ($inspection['role'] ?? null) !== $unit->value
            || ($inspection['restart'] ?? null) !== 'no' || ($inspection['retryCount'] ?? null) !== 0
            || ($inspection['privileged'] ?? null) !== false
            // Docker's empty PidMode denotes its own private PID namespace.
            || ($inspection['pidMode'] ?? null) !== ''
            || ($inspection['cgroupnsMode'] ?? null) !== 'private'
            || ($inspection['capDrop'] ?? null) !== ['ALL']
            || !in_array($inspection['securityOpt'] ?? null, [['no-new-privileges'], ['no-new-privileges:true']], true)
        ) {
            throw new RuntimeException('Container identity or isolation policy does not match containment requirements.');
        }
        if ($this->recipe !== null && ($this->recipe->unit !== $unit
            || ($inspection['memory'] ?? null) !== $this->recipe->memoryBytes
            || ($inspection['memorySwap'] ?? null) !== $this->recipe->memoryBytes
            || ($inspection['nanoCpus'] ?? null) !== $this->recipe->nanoCpus
            || ($inspection['pidsLimit'] ?? null) !== $this->recipe->pidsLimit
            || ($inspection['cpuSet'] ?? null) !== implode(',', $this->recipe->cpuSet))) {
            throw new RuntimeException('Container resource ceilings differ from the admitted unit.');
        }
        if (!array_key_exists('mounts', $inspection)) {
            throw new RuntimeException('Container inspection does not identify its mounts.');
        }
        $this->verifyMounts($inspection['mounts']);
        foreach (['binds', 'tmpfs', 'devices', 'deviceRequests', 'capAdd'] as $field) {
            if (!array_key_exists($field, $inspection) || !in_array($inspection[$field], [null, []], true)) {
                throw new RuntimeException('Container resources do not match containment requirements.');
            }
        }
    }

    private function verifyMounts(mixed $mounts): void
    {
        $expected = $this->recipe->mediaMounts ?? [];
        if ($this->recipe !== null) {
            $expected[] = ['volume' => $this->recipe->ipcVolume, 'target' => '/run/baander-worker', 'readOnly' => false, 'role' => 'ipc'];
        }
        if ($expected === [] && in_array($mounts, [null, []], true)) {
            return;
        }
        if (!is_array($mounts) || !array_is_list($mounts) || count($mounts) !== count($expected)) {
            throw new RuntimeException('Container mounts do not match the admitted media topology.');
        }
        $byTarget = [];
        foreach ($mounts as $mount) {
            if (!is_array($mount) || !is_string($mount['Destination'] ?? null)
                || isset($byTarget[$mount['Destination']])) {
                throw new RuntimeException('Container media mounts require unique destinations.');
            }
            $byTarget[$mount['Destination']] = $mount;
        }
        foreach ($expected as $declaration) {
            $actual = $byTarget[$declaration['target']] ?? [];
            if (($actual['Type'] ?? null) !== 'volume' || ($actual['Name'] ?? null) !== $declaration['volume']
                || ($actual['Driver'] ?? null) !== 'local' || ($actual['RW'] ?? null) !== !$declaration['readOnly']) {
                throw new RuntimeException('Container media volume identity or access mode differs from admission.');
            }
            $format = '{"name":{{json .Name}},"driver":{{json .Driver}},"options":{{json .Options}},"scope":{{json .Scope}},"mountpoint":{{json .Mountpoint}}}';
            try {
                $volume = json_decode(($this->execute)(['volume', 'inspect', '--format', $format, $declaration['volume']]), true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new RuntimeException('Media volume inspection is not valid admission evidence.', 0, $error);
            }
            if (!is_array($volume) || ($volume['name'] ?? null) !== $declaration['volume']
                || ($volume['driver'] ?? null) !== 'local' || ($volume['scope'] ?? null) !== 'local'
                || !array_key_exists('options', $volume) || !in_array($volume['options'], [null, []], true)
                || !is_string($volume['mountpoint'] ?? null) || !str_starts_with($volume['mountpoint'], '/')
                || ($actual['Source'] ?? null) !== $volume['mountpoint']) {
                throw new RuntimeException('Media storage requires the admitted local volume without driver options.');
            }
        }
    }
}
