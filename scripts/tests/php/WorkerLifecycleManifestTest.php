<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Worker\{LifecycleCheckpoint, OperatorConfiguration, OperatorQualification};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerLifecycleManifestTest extends TestCase
{
    public function testCurrentFullProfilePassesLifecyclePreflight(): void
    {
        $this->check($this->manifest());
        self::addToAssertionCount(1);
    }

    #[DataProvider('invalidManifests')]
    public function testStaleOrChangedProfileFailsLifecyclePreflight(string $change): void
    {
        $manifest = $this->manifest();
        match ($change) {
            'version' => $manifest['version'] = 6,
            'memory' => $manifest['controlMemoryMiB'] = 2944,
            'pids' => $manifest['executionPidsLimit'] = 257,
            'cpus' => $manifest['executionCpuSet'] = [1],
            'identifier' => $manifest['deploymentProfile']['identifier'] = 'household',
            'capacity' => $manifest['deploymentProfile']['webWorkers'] = 99,
            'unknown' => $manifest['deploymentProfile']['unknown'] = true,
            'type' => $manifest['deploymentProfile']['webWorkers'] = '4',
            default => throw new \InvalidArgumentException('Unknown test mutation.'),
        };
        $this->expectException(\RuntimeException::class);
        $this->check($manifest);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidManifests(): iterable
    {
        foreach (['version', 'memory', 'pids', 'cpus', 'identifier', 'capacity', 'unknown', 'type'] as $change) {
            yield $change => [$change];
        }
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return ['version' => 7, 'autoscaling' => ['maximumConcurrency' => 2],
            'deploymentProfile' => OperatorConfiguration::json(dirname(__DIR__, 3) . '/tests/Fixtures/Worker/deployment-profile.json')];
    }

    /** @param array<string,mixed> $manifest */
    private function check(array $manifest): void
    {
        $qualification = new \ReflectionClass(OperatorQualification::class)->newInstanceWithoutConstructor();
        new \ReflectionProperty(OperatorQualification::class, 'root')->setValue($qualification, dirname(__DIR__, 3));
        $checkpoint = new LifecycleCheckpoint($qualification);
        new \ReflectionMethod($checkpoint, 'assertManifest')->invoke($checkpoint, $manifest);
    }
}
