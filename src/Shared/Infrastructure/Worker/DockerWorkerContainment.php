<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** External-controller proof: successful immutable-ID removal, never stopped-state observation. */
#[Exclude]
final readonly class DockerWorkerContainment
{
    private const string INSPECT_FORMAT = <<<'FORMAT'
{"id":{{json .Id}},"namespace":{{json (index .Config.Labels "app.baander.worker.namespace")}},"bootId":{{json (index .Config.Labels "app.baander.worker.boot-id")}},"role":{{json (index .Config.Labels "app.baander.worker.role")}},"privileged":{{json .HostConfig.Privileged}},"pidMode":{{json .HostConfig.PidMode}},"cgroupnsMode":{{json .HostConfig.CgroupnsMode}},"mounts":{{json .Mounts}},"binds":{{json (index .HostConfig "Binds")}},"tmpfs":{{json (index .HostConfig "Tmpfs")}},"devices":{{json (index .HostConfig "Devices")}},"deviceRequests":{{json (index .HostConfig "DeviceRequests")}},"capAdd":{{json (index .HostConfig "CapAdd")}},"capDrop":{{json .HostConfig.CapDrop}},"securityOpt":{{json .HostConfig.SecurityOpt}}}
FORMAT;

    /** @param Closure(list<string>): string $execute Successful commands return stdout; every command failure throws. */
    public function __construct(private Closure $execute)
    {
    }

    /**
     * Only the private PID/cgroup namespace policy is accepted, with no mounts,
     * devices or added capabilities, all capabilities dropped, and no-new-privileges.
     * The trusted controller creates one deployment container per fresh boot and
     * records its immutable ID and labels before starting it. These labels do not
     * defend against a malicious Docker operator; durable boot-to-ID inventory is
     * the caller's responsibility. Removal itself supplies proof for that known ID.
     */
    public function retire(string $containerId, DeploymentLease $lease): void
    {
        $this->verifyIsolation($containerId, $lease->namespace, $lease->bootId);
        $removed = ($this->execute)(['container', 'rm', '--force', $containerId]);
        if (trim($removed) !== $containerId) {
            throw new RuntimeException('Immutable container removal was not confirmed.');
        }
    }

    /** Inspection verifies identity and isolation only; it supplies no retirement or start receipt. */
    public function verifyIsolation(string $containerId, string $namespace, string $bootId): void
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
        if (!is_array($inspection) || count($inspection) !== 15
            || ($inspection['id'] ?? null) !== $containerId
            || ($inspection['namespace'] ?? null) !== $namespace
            || ($inspection['bootId'] ?? null) !== $bootId
            || ($inspection['role'] ?? null) !== 'deployment'
            || ($inspection['privileged'] ?? null) !== false
            // Docker's empty PidMode denotes its own private PID namespace.
            || ($inspection['pidMode'] ?? null) !== ''
            || ($inspection['cgroupnsMode'] ?? null) !== 'private'
            || ($inspection['capDrop'] ?? null) !== ['ALL']
            || !in_array($inspection['securityOpt'] ?? null, [['no-new-privileges'], ['no-new-privileges:true']], true)
        ) {
            throw new RuntimeException('Container identity or isolation policy does not match containment requirements.');
        }
        foreach (['mounts', 'binds', 'tmpfs', 'devices', 'deviceRequests', 'capAdd'] as $field) {
            if (!array_key_exists($field, $inspection) || !in_array($inspection[$field], [null, []], true)) {
                throw new RuntimeException('Container resources do not match containment requirements.');
            }
        }
    }
}
