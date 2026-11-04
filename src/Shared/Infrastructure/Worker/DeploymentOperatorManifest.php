<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Fixed paired topology; every admitted field participates in the boot fingerprint. */
#[Exclude]
final readonly class DeploymentOperatorManifest
{
    private const array STRING_FIELDS = ['namespace', 'bootId', 'daemonId', 'imageId', 'network', 'dockerBinary', 'dockerEndpoint', 'ipcVolume', 'sourceMountPolicy'];
    private const array INTEGER_FIELDS = ['version', 'scheduledConsoleMiB'];
    private const int MIB = 1024 * 1024;

    /** @param list<array{volume?:string,source?:string,target:string,readOnly:bool,role:string}> $mediaMounts */
    private function __construct(
        public string $namespace,
        public string $bootId,
        public string $daemonId,
        public string $imageId,
        public string $appHome,
        public string $network,
        public string $dockerBinary,
        public string $dockerEndpoint,
        public int $scheduledConsoleBytes,
        public string $ipcVolume,
        public array $mediaMounts,
        public WorkerAutoscalingProfile $autoscaling,
        public string $sourceMountPolicy,
        public DeploymentProfile $deploymentProfile,
        public ?int $deviceGroup,
    ) {
        if (($deploymentProfile->hardware?->device === null) !== ($deviceGroup === null)) {
            throw new InvalidArgumentException('Operator manifest requires a device group exactly when the deployment profile names a hardware encoder device.');
        }
        if (!str_starts_with($dockerBinary, '/') || strlen($dockerBinary) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $dockerBinary) !== 0
            || strlen($dockerEndpoint) > 4096
            || preg_match('~\Aunix:///[^\x00-\x20\x7f]+\z~D', $dockerEndpoint) !== 1) {
            throw new InvalidArgumentException('Operator manifest requires an absolute Docker binary and local Unix endpoint.');
        }
        if ($autoscaling->maximumConcurrency > $deploymentProfile->ordinaryConsumerLimit
            || ($scheduledConsoleBytes !== 0 && $scheduledConsoleBytes < $deploymentProfile->phpProcessMemoryMiB() * self::MIB)) {
            throw new InvalidArgumentException('Worker manifest requires an ordinary concurrency within the profile and a scheduled console reservation of at least one PHP process.');
        }
        foreach (DeploymentUnit::cases() as $unit) {
            $this->recipe($unit, new DeploymentRuntimeEnvironment(['BAANDER_DEPLOYMENT_PROFILE' => $deploymentProfile->identifier,
                'BAANDER_DEPLOYMENT_PROFILE_JSON' => $deploymentProfile->toJson()]));
        }
    }

    /**
     * A null `deviceGroup` with a hardware encoder device takes the group that owns the device,
     * as the given resolver reports it on the Docker host; without a resolver it is rejected.
     *
     * @param ?\Closure(string): int $deviceGroupOf
     */
    public static function fromJson(string $json, string $appHome, ?\Closure $deviceGroupOf = null): self
    {
        if (strlen($json) > 8192) {
            throw new InvalidArgumentException('Operator manifest exceeds 8192 bytes.');
        }
        try {
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Operator manifest must be a bounded JSON object.');
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('Operator manifest must be a JSON object.');
        }
        $fields = get_object_vars($decoded);
        $expected = [...self::STRING_FIELDS, ...self::INTEGER_FIELDS, 'mediaMounts', 'autoscaling', 'deploymentProfile', 'deviceGroup'];
        if (array_diff(array_keys($fields), $expected) !== [] || array_diff($expected, array_keys($fields)) !== []) {
            throw new InvalidArgumentException('Operator manifest has unknown or missing fields.');
        }
        if (self::integer($fields, 'version') !== 8) {
            throw new InvalidArgumentException('Operator manifest version must be 8.');
        }
        if (!is_array($fields['mediaMounts']) || !array_is_list($fields['mediaMounts'])) {
            throw new InvalidArgumentException('Operator media mounts require an ordered list.');
        }
        $mounts = [];
        foreach ($fields['mediaMounts'] as $mount) {
            if (!$mount instanceof stdClass) {
                throw new InvalidArgumentException('Operator media mounts require objects.');
            }
            $mounts[] = get_object_vars($mount);
        }
        $profile = json_encode($fields['deploymentProfile'], JSON_THROW_ON_ERROR) |> DeploymentProfile::fromJson(...);
        $deviceGroup = $fields['deviceGroup'];
        if ($deviceGroup !== null && !is_int($deviceGroup)) {
            throw new InvalidArgumentException('Operator manifest device group requires a JSON integer or null.');
        }
        $device = $profile->hardware?->device;
        if ($deviceGroup === null && $device !== null && $deviceGroupOf !== null) {
            $deviceGroup = $deviceGroupOf($device);
        }
        return new self(self::string($fields, 'namespace'), self::string($fields, 'bootId'), self::string($fields, 'daemonId'),
            self::string($fields, 'imageId'), $appHome, self::string($fields, 'network'), self::string($fields, 'dockerBinary'), self::string($fields, 'dockerEndpoint'),
            self::bytes($fields, 'scheduledConsoleMiB'), self::string($fields, 'ipcVolume'), $mounts,
            WorkerAutoscalingProfile::fromJson(json_encode($fields['autoscaling'], JSON_THROW_ON_ERROR)), self::string($fields, 'sourceMountPolicy'),
            $profile, $deviceGroup);
    }

    /** The group that owns a device node on this host, for a manifest that leaves it to the host. */
    public static function hostDeviceGroup(string $device): int
    {
        clearstatcache(true, $device);
        $status = @stat($device);
        if ($status === false || !in_array(@filetype($device), ['char', 'block'], true)) {
            throw new InvalidArgumentException('The hardware encoder device is not a device node on this host; set the manifest deviceGroup explicitly.');
        }
        return $status['gid'];
    }

    public function recipe(DeploymentUnit $unit, DeploymentRuntimeEnvironment $environment): DeploymentContainerRecipe
    {
        if (DeploymentProfile::resolve($environment->variables['BAANDER_DEPLOYMENT_PROFILE'] ?? 'full',
            $environment->variables['BAANDER_DEPLOYMENT_PROFILE_JSON'] ?? null) != $this->deploymentProfile) {
            throw new InvalidArgumentException('Worker manifest and web deployment profiles must agree.');
        }
        $recipe = new DeploymentContainerRecipe($this->namespace, $this->bootId, $this->daemonId, $this->imageId,
            ['/usr/local/bin/php', '-d', 'memory_limit=' . $this->deploymentProfile->phpHeapMiB() . 'M', $this->appHome . '/bin/console', 'app:worker', '--no-interaction',
                '--deployment=' . $this->namespace, '--boot-id=' . $this->bootId, '--unit=' . $unit->value,
                '--scheduled-console-mib=' . ($unit === DeploymentUnit::Control ? 0 : intdiv($this->scheduledConsoleBytes, self::MIB)),
                '--autoscaling-profile=' . $this->autoscaling->toJson(),
                '--deployment-profile=' . $this->deploymentProfile->toJson(),
                '--lock-dir=/tmp/baander-worker-locks', '--ipc-dir=/run/baander-worker'],
            $this->network, $unit, $this->ipcVolume, $this->appHome, $this->deploymentProfile, $environment, $this->mediaMounts, $this->sourceMountPolicy, $this->deviceGroup);
        if (!in_array('source', array_column($recipe->mediaMounts, 'role'), true)) {
            throw new InvalidArgumentException('Operator media topology requires an explicitly authorized source storage.');
        }
        $paths = [[$this->appHome . '/storage', 'derived'], [$this->appHome . '/var/transcode_state', 'derived']];
        foreach (['CONVERT_STORAGE_PATH' => 'derived', 'MEDIA_STORAGE_PATH' => 'source'] as $key => $role) {
            if (!isset($environment->variables[$key])) {
                continue;
            }
            $path = $environment->variables[$key];
            if (strlen($path) > 4096 || preg_match('~\A/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z~D', $path) !== 1
                || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path) !== 0) {
                throw new InvalidArgumentException('Media storage requires a canonical absolute shared-media path.');
            }
            $paths[] = [$path, $role];
        }
        foreach ($paths as [$path, $role]) {
            $covered = false;
            foreach ($recipe->mediaMounts as $mount) {
                if ($mount['role'] === $role && ($path === $mount['target'] || str_starts_with($path, $mount['target'] . '/'))) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                throw new InvalidArgumentException('Operator media topology does not cover required ' . ($role === 'source' ? 'source' : 'writable') . ' storage: ' . $path);
            }
        }
        return $recipe;
    }

    public function fingerprint(DeploymentRuntimeEnvironment $environment): string
    {
        return hash('sha256', $this->recipe(DeploymentUnit::Control, $environment)->fingerprint()
            . $this->recipe(DeploymentUnit::Execution, $environment)->fingerprint());
    }

    /** @param array<string,mixed> $fields */
    private static function string(array $fields, string $key): string
    {
        $value = $fields[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException('Operator manifest string fields require JSON strings.');
        }
        return $value;
    }

    /** @param array<string,mixed> $fields */
    private static function integer(array $fields, string $key): int
    {
        $value = $fields[$key];
        if (!is_int($value)) {
            throw new InvalidArgumentException('Operator manifest integer fields require JSON integers.');
        }
        return $value;
    }

    /** @param array<string,mixed> $fields */
    private static function bytes(array $fields, string $key): int
    {
        $value = self::integer($fields, $key);
        if ($value < 0 || $value > 1048576) {
            throw new InvalidArgumentException('The scheduled console reservation requires a MiB value between 0 and 1048576.');
        }
        return $value * self::MIB;
    }
}
