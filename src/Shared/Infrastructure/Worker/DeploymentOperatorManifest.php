<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use App\Shared\Application\DTO\DeploymentUnit;
use App\Shared\Application\DTO\WorkerAutoscalingProfile;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Fixed paired topology; every admitted field participates in the boot fingerprint. */
#[Exclude]
final readonly class DeploymentOperatorManifest
{
    private const array STRING_FIELDS = ['namespace', 'bootId', 'daemonId', 'imageId', 'network', 'dockerBinary', 'dockerEndpoint', 'ipcVolume'];
    private const array INTEGER_FIELDS = ['version', 'controlMemoryMiB', 'executionMemoryMiB', 'controlNanoCpus', 'executionNanoCpus', 'controlPidsLimit', 'executionPidsLimit', 'scheduledConsoleMiB'];
    private const int MIB = 1024 * 1024;

    /** @param list<array{volume:string,target:string,readOnly:bool,role:string}> $mediaMounts */
    private function __construct(
        public string $namespace,
        public string $bootId,
        public string $daemonId,
        public string $imageId,
        public string $network,
        public string $dockerBinary,
        public string $dockerEndpoint,
        public DeploymentResourceBudget $control,
        public DeploymentResourceBudget $execution,
        public int $scheduledConsoleBytes,
        public string $ipcVolume,
        public array $mediaMounts,
        public WorkerAutoscalingProfile $autoscaling,
    ) {
        if (!str_starts_with($dockerBinary, '/') || strlen($dockerBinary) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $dockerBinary) !== 0
            || strlen($dockerEndpoint) > 4096
            || preg_match('~\Aunix:///[^\x00-\x20\x7f]+\z~D', $dockerEndpoint) !== 1) {
            throw new InvalidArgumentException('Operator manifest requires an absolute Docker binary and local Unix endpoint.');
        }
        if (array_intersect($control->cpuSet, $execution->cpuSet) !== []) {
            throw new InvalidArgumentException('Control and execution require disjoint CPU sets.');
        }
        if ($control->memoryBytes < $autoscaling->minimumMemoryMiB('control') * self::MIB
            || $execution->memoryBytes < $autoscaling->minimumMemoryMiB('execution') * self::MIB + $scheduledConsoleBytes
            || $control->pidsLimit < $autoscaling->minimumPids('control') || $execution->pidsLimit < $autoscaling->minimumPids('execution')
            || ($scheduledConsoleBytes !== 0 && $scheduledConsoleBytes < 320 * self::MIB)) {
            throw new InvalidArgumentException('Worker budgets must cover control management, autoscaling, media PHP, every resident consumer, native execution and any scheduled console child.');
        }
        foreach (DeploymentUnit::cases() as $unit) {
            $this->recipe($unit, new DeploymentRuntimeEnvironment());
        }
    }

    public static function fromJson(string $json): self
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
        $expected = [...self::STRING_FIELDS, ...self::INTEGER_FIELDS, 'controlCpuSet', 'executionCpuSet', 'mediaMounts', 'autoscaling'];
        if (array_diff(array_keys($fields), $expected) !== [] || array_diff($expected, array_keys($fields)) !== []) {
            throw new InvalidArgumentException('Operator manifest has unknown or missing fields.');
        }
        if (self::integer($fields, 'version') !== 4) {
            throw new InvalidArgumentException('Operator manifest version must be 4.');
        }
        $budgets = [];
        foreach (DeploymentUnit::cases() as $unit) {
            $prefix = $unit->value;
            if (!is_array($fields[$prefix . 'CpuSet'])) {
                throw new InvalidArgumentException('Worker CPU sets require integer lists.');
            }
            $budgets[$prefix] = new DeploymentResourceBudget(self::bytes($fields, $prefix . 'MemoryMiB'),
                self::integer($fields, $prefix . 'NanoCpus'), self::integer($fields, $prefix . 'PidsLimit'), $fields[$prefix . 'CpuSet']);
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
        return new self(self::string($fields, 'namespace'), self::string($fields, 'bootId'), self::string($fields, 'daemonId'),
            self::string($fields, 'imageId'), self::string($fields, 'network'), self::string($fields, 'dockerBinary'), self::string($fields, 'dockerEndpoint'),
            $budgets['control'], $budgets['execution'], self::bytes($fields, 'scheduledConsoleMiB'), self::string($fields, 'ipcVolume'), $mounts,
            WorkerAutoscalingProfile::fromJson(json_encode($fields['autoscaling'], JSON_THROW_ON_ERROR)));
    }

    public function recipe(DeploymentUnit $unit, DeploymentRuntimeEnvironment $environment): DeploymentContainerRecipe
    {
        $budget = $unit === DeploymentUnit::Control ? $this->control : $this->execution;
        $recipe = new DeploymentContainerRecipe($this->namespace, $this->bootId, $this->daemonId, $this->imageId,
            ['/usr/local/bin/php', '-d', 'memory_limit=256M', '/var/www/html/bin/console', 'app:worker', '--no-interaction',
                '--deployment=' . $this->namespace, '--boot-id=' . $this->bootId, '--unit=' . $unit->value,
                '--memory-mib=' . intdiv($budget->memoryBytes, self::MIB), '--pids-limit=' . $budget->pidsLimit,
                '--scheduled-console-mib=' . ($unit === DeploymentUnit::Control ? 0 : intdiv($this->scheduledConsoleBytes, self::MIB)),
                '--autoscaling-profile=' . $this->autoscaling->toJson(),
                '--lock-dir=/tmp/baander-worker-locks', '--ipc-dir=/run/baander-worker'],
            $this->network, $budget->memoryBytes, $budget->nanoCpus, $budget->pidsLimit, $unit, $budget->cpuSet, $this->ipcVolume, $environment, $this->mediaMounts);
        if (!in_array('source', array_column($recipe->mediaMounts, 'role'), true)) {
            throw new InvalidArgumentException('Operator media topology requires an explicitly authorized source volume.');
        }
        $paths = [['/var/www/html/storage', 'derived'], ['/var/www/html/var/transcode_state', 'derived']];
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
            throw new InvalidArgumentException('Operator memory fields require MiB values between 0 and 1048576.');
        }
        return $value * self::MIB;
    }
}
