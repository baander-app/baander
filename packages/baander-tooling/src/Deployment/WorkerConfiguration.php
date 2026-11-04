<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use Baander\Tooling\Configuration\ApplicationLayout;

final readonly class WorkerConfiguration
{
    /** @param array<string,mixed> $target
     * @param array<string,string> $runtimeEnvironment
     * @param list<array<string,mixed>> $mediaMounts
     * @param array<string,string> $resourceLabels
     */
    public function __construct(
        public string $namespace,
        public array $target,
        public string $network,
        public string $workerImage,
        public string $operatorImage,
        public ApplicationLayout $layout,
        public array $runtimeEnvironment,
        public string $sourceMountPolicy,
        public array $mediaMounts,
        public array $resourceLabels,
        public ImageProfile $profile,
        public float $cleanupSeconds = 120.0,
        private ?int $deviceGroup = null,
    ) {
        if (!in_array($sourceMountPolicy, ['production', 'local-development'], true)) {
            throw new \InvalidArgumentException('Unknown worker source mount policy.');
        }
        if ($sourceMountPolicy === 'production' && array_any($mediaMounts, static fn(array $mount): bool => isset($mount['source']))) {
            throw new \InvalidArgumentException('Production worker mounts require named volumes.');
        }
        if ($cleanupSeconds <= 0 || !is_finite($cleanupSeconds)) {
            throw new \InvalidArgumentException('Worker cleanup budget must be finite and positive.');
        }
    }

    /**
     * The group the execution worker joins to open the profile's hardware encoder device: the
     * configured override, or the group that owns the device node on this Docker host.
     */
    public function deviceGroup(): ?int
    {
        $device = $this->profile->deployment['hardware']['device'] ?? null;
        if (!is_string($device)) {
            return null;
        }
        if ($this->deviceGroup !== null) {
            return $this->deviceGroup;
        }
        clearstatcache(true, $device);
        $status = @stat($device);
        if ($status === false || !in_array(@filetype($device), ['char', 'block'], true)) {
            throw new \RuntimeException('The hardware encoder device ' . $device . ' is not a device node on this host; set media.hardware.group to override its group.');
        }
        return $status['gid'];
    }

    /** @return list<string> */
    public function labelArguments(): array
    {
        $arguments = [];
        foreach ($this->resourceLabels as $name => $value) {
            array_push($arguments, '--label', $name . '=' . $value);
        }
        return $arguments;
    }
}
