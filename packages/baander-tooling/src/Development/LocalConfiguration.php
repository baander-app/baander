<?php

declare(strict_types=1);

namespace Baander\Tooling\Development;

final class LocalConfiguration
{
    /** @return list<array{slug:string,name:string,type:string,source:string,target:string}>|null */
    public static function libraries(Runner $runner, string $root): ?array
    {
        $path = $root . '/dev.local.json';
        if (!file_exists($path)) {
            $metadata = $runner->run(['git', 'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir'], cwd: $root, check: false);
            $directories = explode("\n", trim($metadata->stdout));
            if ($metadata->exitCode !== 0 || count($directories) !== 2 || $directories[0] === $directories[1]) {
                return null;
            }
            $worktrees = $runner->run(['git', 'worktree', 'list', '--porcelain', '-z'], cwd: $root);
            $primary = explode("\0", $worktrees->stdout)[0];
            $checkout = substr($primary, strlen('worktree '));
            $configured = $runner->run(['git', 'config', '--get', 'core.worktree'], cwd: $root, check: false);
            if ($configured->exitCode === 0) {
                $checkout = trim($configured->stdout);
                if (!str_starts_with($checkout, '/')) {
                    $checkout = $directories[1] . '/' . $checkout;
                }
            }
            // Separate Git directories need core.worktree to record the primary checkout.
            if (!file_exists($checkout . '/.git')) {
                return null;
            }
            $candidate = $runner->run(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], cwd: $checkout, check: false);
            if (
                $candidate->exitCode !== 0
                || Environment::resolvedPath(trim($candidate->stdout)) !== Environment::resolvedPath($directories[1])
            ) {
                return null;
            }
            $path = $checkout . '/dev.local.json';
            if (!file_exists($path)) {
                return null;
            }
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new WorkflowError('Cannot read development configuration: ' . $path);
        }
        try {
            $configuration = json_decode($content, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new WorkflowError('Invalid JSON in ' . $path, previous: $error);
        }
        if (
            !$configuration instanceof \stdClass
            || array_diff(array_keys(get_object_vars($configuration)), ['libraries']) !== []
        ) {
            throw new WorkflowError('dev.local.json must be an object containing only libraries.');
        }
        if (!property_exists($configuration, 'libraries')) {
            return null;
        }
        if (!is_array($configuration->libraries)) {
            throw new WorkflowError('dev.local.json libraries must be an array.');
        }
        $libraries = [];
        $slugs = [];
        foreach ($configuration->libraries as $entry) {
            if (
                !$entry instanceof \stdClass
                || array_diff(array_keys(get_object_vars($entry)), ['slug', 'name', 'type', 'path']) !== []
            ) {
                throw new WorkflowError('Each development library must contain only slug, name, type and path.');
            }
            foreach (['slug', 'name', 'type', 'path'] as $field) {
                if (!isset($entry->$field) || !is_string($entry->$field) || trim($entry->$field) === '') {
                    throw new WorkflowError('Each development library requires a nonempty ' . $field . ' string.');
                }
            }
            $slug = strtolower(trim($entry->slug));
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
                'source' => Environment::resolvedPath($entry->path),
                'target' => '/media/' . $slug,
            ];
        }
        return $libraries;
    }
}
