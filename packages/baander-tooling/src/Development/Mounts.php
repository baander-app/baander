<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Filesystem\Paths;

final readonly class Mounts
{
    public function __construct(private ApplicationLayout $layout) {}

    /**
     * Validate the complete effective media set before creating instance state.
     * Library identity fields are retained. The media volume may be replaced;
     * application, conversion and service state mounts may not be obscured.
     *
     * @param list<array<string,mixed>> $mounts
     * @param array<string,string> $environment
     * @param list<string> $reservedTargets Additional saved runtime state destinations
     * @return list<array<string,mixed>>
     */
    public function preflight(array $mounts, ?string $stateRoot = null, array $environment = [], array $reservedTargets = []): array
    {
        $reserved = array_map(self::destination(...), [
            $this->layout->root,
            '/etc',
            '/run',
            '/var/run',
            '/proc',
            '/sys',
            '/dev',
            '/data',
            '/var/lib/postgresql',
            $environment['CONVERT_STORAGE_PATH'] ?? '/storage/conversions',
            ...$reservedTargets,
        ]);
        $mediaTarget = self::destination($environment['MEDIA_STORAGE_PATH'] ?? '/storage/media');
        $state = $stateRoot === null ? null : Paths::resolvedPath($stateRoot);
        $validated = [];
        $targets = [];
        foreach ($mounts as $mount) {
            $source = $mount['source'] ?? null;
            $target = $mount['target'] ?? null;
            if (!is_string($source) || !is_string($target)) {
                throw new WorkflowError('Media mounts require source and target strings.');
            }
            self::mountValue($source);
            if (!str_starts_with($source, '/') || !is_dir($source) || !is_readable($source)) {
                throw new WorkflowError('Configure an existing absolute readable media directory.');
            }
            $resolved = realpath($source);
            if ($resolved === false) {
                throw new WorkflowError('Cannot resolve media directory.');
            }
            self::mountValue($resolved);
            $target = self::destination($target);
            if ($state !== null && self::overlaps($resolved, $state)) {
                throw new WorkflowError('Media directories must not contain development state or be inside it.');
            }
            if ($target !== $mediaTarget && self::overlaps($target, $mediaTarget)) {
                throw new WorkflowError('Media mount overlaps the reserved media volume: ' . $target);
            }
            foreach ($reserved as $reservedTarget) {
                if (self::overlaps($target, $reservedTarget)) {
                    throw new WorkflowError('Media mount overlaps application or system state: ' . $target);
                }
            }
            foreach ($targets as $existing) {
                if (self::overlaps($target, $existing)) {
                    throw new WorkflowError('Media mount targets overlap: ' . $target);
                }
            }
            $targets[] = $target;
            $validated[] = [...$mount, 'source' => $resolved, 'target' => $target];
        }
        return $validated;
    }

    private static function mountValue(string $value): void
    {
        if ($value === '' || preg_match('/[\x00-\x1f\x7f,"]/', $value) === 1) {
            throw new WorkflowError('Mount paths must not contain commas, quotes or control characters.');
        }
    }

    private static function destination(string $path): string
    {
        self::mountValue($path);
        if (!str_starts_with($path, '/')) {
            throw new WorkflowError('Media mount destinations must be absolute container paths.');
        }
        return Paths::absolutePath($path);
    }

    private static function overlaps(string $left, string $right): bool
    {
        return $left === $right
            || str_starts_with($left, rtrim($right, '/') . '/')
            || str_starts_with($right, rtrim($left, '/') . '/');
    }
}
