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
