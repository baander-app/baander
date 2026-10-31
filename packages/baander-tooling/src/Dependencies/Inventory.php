<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

use Baander\Tooling\Filesystem\PrivateDirectory;

final readonly class Inventory
{
    /**
     * @param array<string,mixed> $policy
     * @param array<string,array<string,mixed>> $projects
     * @param list<array{path:string,reason:string}> $gaps
     */
    private function __construct(public array $policy, public array $projects, public array $gaps) {}

    public static function load(string $root): self
    {
        $policy = self::json($root . '/packages/baander-tooling/resources/php-dependencies.json');
        if (($policy['version'] ?? null) !== 1 || !is_array($policy['projects'] ?? null)) {
            throw new \RuntimeException('Unsupported dependency policy schema.');
        }
        $excluded = [];
        foreach ($policy['excludedTrees'] ?? [] as $path => $classification) {
            self::path($path);
            if (!is_array($classification) || !in_array($classification['kind'] ?? null, ['generated', 'fixture'], true) || !is_string($classification['reason'] ?? null) || $classification['reason'] === '') {
                throw new \RuntimeException('Excluded manifest trees require an explicit generated/fixture classification and reason.');
            }
            foreach (array_keys($policy['projects']) as $project) {
                if ($project === $path || str_starts_with($project, $path . '/')) {
                    throw new \RuntimeException('An excluded tree cannot contain a classified source project.');
                }
            }
            $excluded[] = $path;
        }
        $discovered = [];
        self::discover($root, '', $discovered, $excluded);
        $projects = [];
        $gaps = [];
        foreach (array_unique([...$discovered, ...array_keys($policy['projects'])]) as $path) {
            try {
                self::path($path);
                $classification = $policy['projects'][$path] ?? null;
                if (!is_array($classification) || !in_array($classification['kind'] ?? null, ['locked', 'package', 'fork', 'fixture'], true) || !is_string($classification['owner'] ?? null)) {
                    throw new \RuntimeException('Manifest needs an explicit project classification and owner.');
                }
                if ($classification['kind'] === 'fixture') {
                    if (empty($classification['reason'])) {
                        throw new \RuntimeException('Fixture exclusion needs a reason.');
                    }
                    continue;
                }
                $manifest = self::json($root . '/' . $path);
                $lock = null;
                if ($classification['kind'] === 'locked') {
                    $lockPath = $classification['lock'] ?? '';
                    self::path($lockPath);
                    try {
                        $lock = self::json($root . '/' . $lockPath);
                        foreach (['packages', 'packages-dev'] as $section) {
                            if (!is_array($lock[$section] ?? null) || !array_is_list($lock[$section])) {
                                throw new \RuntimeException('Lock must contain packages and packages-dev lists.');
                            }
                        }
                    } catch (\Throwable $error) {
                        $gaps[] = ['path' => $lockPath, 'reason' => $error->getMessage()];
                    }
                }
                $requirements = [];
                foreach (['require', 'require-dev'] as $section) {
                    foreach ($manifest[$section] ?? [] as $name => $constraint) {
                        if (!is_string($name) || !is_string($constraint)) {
                            throw new \RuntimeException('Malformed direct requirement.');
                        }
                        $requirements[] = ['name' => $name, 'constraint' => $constraint, 'section' => $section];
                    }
                }
                $projects[$path] = ['classification' => $classification, 'manifest' => $manifest, 'lock' => $lock, 'requirements' => $requirements];
            } catch (\Throwable $error) {
                $gaps[] = ['path' => $path, 'reason' => $error->getMessage()];
            }
        }
        ksort($projects);
        return new self($policy, $projects, $gaps);
    }

    /**
     * @return array<string,mixed>
     */
    public static function json(string $path): array
    {
        PrivateDirectory::assertNoSymlinks($path);
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Cannot read ' . $path);
        }
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('Expected JSON object in ' . $path);
        }
        return $data;
    }

    public static function path(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('Policy paths must be repository-relative without traversal.');
        }
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excluded
     */
    private static function discover(string $root, string $relative, array &$paths, array $excluded): void
    {
        $entries = scandir($root . ($relative === '' ? '' : '/' . $relative));
        if ($entries === false) {
            throw new \RuntimeException('Cannot discover manifests in ' . $relative);
        }
        foreach ($entries as $entry) {
            if (in_array($entry, ['.', '..', '.git', 'vendor', 'node_modules'], true)) {
                continue;
            }
            $path = ($relative === '' ? '' : $relative . '/') . $entry;
            $absolute = $root . '/' . $path;
            if (is_link($absolute) || in_array($path, $excluded, true)) {
                continue;
            }
            if (is_dir($absolute)) {
                self::discover($root, $path, $paths, $excluded);
            } elseif ($entry === 'composer.json') {
                $paths[] = $path;
            }
        }
    }
}
