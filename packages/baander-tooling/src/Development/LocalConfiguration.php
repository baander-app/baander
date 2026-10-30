<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

use Baander\Tooling\Process\{CommandExecutor, ProcessSpec, CompletionPolicy};
use Baander\Tooling\Configuration\LocalFile;

final class LocalConfiguration
{
    public static function discover(CommandExecutor $runner, string $root): ?string
    {
        return \Baander\Tooling\Configuration\RepositoryConfiguration::discover($runner, $root);
    }

    /** @param array<string,string>|null $environment
     * @return array<string,mixed>
     */
    public static function load(CommandExecutor $runner, string $root, ?array $environment = null): array
    {
        try {
            return \Baander\Tooling\Configuration\RepositoryConfiguration::local($runner, $root, $environment ?? getenv());
        } catch (\InvalidArgumentException $error) {
            throw new WorkflowError($error->getMessage(), previous: $error);
        }
    }

    /**
     * @param array<string,mixed>|null $configuration
     * @return list<array{slug:string,name:string,type:string,source:string,target:string}>|null
     * @phpstan-impure
     */
    public static function libraries(CommandExecutor $runner, string $root, ?array $configuration = null): ?array
    {
        $configuration ??= self::load($runner, $root);
        if (!array_key_exists('libraries', $configuration)) {
            return null;
        }
        $libraries = [];
        $slugs = [];
        foreach ($configuration['libraries'] as $entry) {
            if (
                !$entry instanceof \stdClass
                || array_diff($entry |> get_object_vars(...) |> array_keys(...), ['slug', 'name', 'type', 'path']) !== []
            ) {
                throw new WorkflowError('Each development library must contain only slug, name, type and path.');
            }
            foreach (['slug', 'name', 'type', 'path'] as $field) {
                if (!isset($entry->$field) || !is_string($entry->$field) || trim($entry->$field) === '') {
                    throw new WorkflowError('Each development library requires a nonempty ' . $field . ' string.');
                }
            }
            $slug = $entry->slug |> trim(...) |> strtolower(...);
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || isset($slugs[$slug])) {
                throw new WorkflowError('Development library slugs must be valid and unique.');
            }
            if (!in_array($entry->type, ['music', 'podcast', 'audiobook', 'movie', 'tv_show'], true)) {
                throw new WorkflowError('Unsupported development library type: ' . $entry->type);
            }
            if (!str_starts_with($entry->path, '/') || !is_dir($entry->path) || !is_readable($entry->path)) {
                throw new WorkflowError('Development library path must be an absolute readable existing directory.');
            }
            $slugs[$slug] = true;
            $libraries[] = [
                'slug' => $slug,
                'name' => $entry->name,
                'type' => $entry->type,
                'source' => \Baander\Tooling\Filesystem\Paths::resolvedPath($entry->path),
                'target' => '/media/' . $slug,
            ];
        }
        return $libraries;
    }
}
