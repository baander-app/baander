<?php

declare(strict_types=1);

namespace Baander\Tooling\JavaScriptDependencies;

final class Inventory
{
    /** @return array<string,mixed> */
    public static function json(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Missing file: ' . $path);
        }
        $value = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('Expected a JSON object: ' . $path);
        }
        return $value;
    }

    /** @param array<string,mixed> $policy
     * @return array<string,array<string,mixed>>
     */
    public static function collect(string $root, array $policy, Report $report): array
    {
        self::validatePolicy($policy);
        $projects = [];
        foreach ($policy['scopeDirectories'] as $directory) {
            foreach (self::manifests($root . '/' . $directory) as $file) {
                $path = substr($file, strlen($root) + 1);
                if (!isset($policy['projects'][$path])) {
                    $report->add('finding', $path, 'inventory', 'Manifest has no scoped ownership classification.');
                }
            }
        }
        foreach ($policy['projects'] as $path => $classification) {
            try {
                $manifest = self::json($root . '/' . $path);
                $requirements = [];
                foreach (['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
                    if (isset($manifest[$section]) && !is_array($manifest[$section])) {
                        throw new \RuntimeException('Malformed dependency section: ' . $section);
                    }
                    foreach ($manifest[$section] ?? [] as $name => $constraint) {
                        if (!is_string($name) || !is_string($constraint) || $constraint === '') {
                            throw new \RuntimeException('Malformed dependency requirement.');
                        }
                        $requirements[$name] = $constraint;
                    }
                }
                if ($classification['kind'] === 'locked') {
                    $lock = $root . '/' . $classification['lock'];
                    if (!is_file($lock) || filesize($lock) === 0) {
                        $report->add('finding', $path, 'lock', 'Owned lockfile is missing or empty.', $classification);
                    } elseif ($classification['manager'] === 'npm') {
                        $locked = self::json($lock);
                        if (
                            ($locked['packages']['']['devDependencies'] ?? []) !== ($manifest['devDependencies'] ?? [])
                            || ($locked['packages']['']['dependencies'] ?? []) !== ($manifest['dependencies'] ?? [])
                        ) {
                            $report->add('finding', $path, 'lock', 'npm root requirements differ from the owned lock.');
                        }
                    }
                } elseif ($classification['kind'] === 'dependency-free' && $requirements !== []) {
                    $report->add('finding', $path, 'manifest', 'Dependency-free project declares dependencies.');
                }
                $report->add('evidence', $path, 'manifest', 'Scoped manifest and lock ownership inspected.', ['classification' => $classification, 'requirements' => $requirements, 'resolutions' => $manifest['resolutions'] ?? []]);
                foreach ($manifest['resolutions'] ?? [] as $selector => $value) {
                    $owned = $policy['resolutions'][$path][$selector] ?? [];
                    $valid = ($owned['value'] ?? null) === $value && !empty($owned['reason']);
                    $report->add($valid ? 'evidence' : 'finding', $path, 'resolution', $valid ? $owned['reason'] : 'Resolution lacks matching policy ownership and rationale.', ['selector' => $selector, 'value' => $value]);
                }
                self::patches($root, $path, $policy, $report);
                $projects[$path] = ['classification' => $classification, 'requirements' => $requirements, 'manifest' => $manifest];
            } catch (\Throwable $error) {
                $report->add('finding', $path, 'manifest', $error->getMessage());
            }
        }
        $configuration = $root . '/packages/baander-tooling/resources/tooling-defaults.json';
        if (is_file($configuration)) {
            $defaults = self::json($configuration);
            $browser = $defaults['images']['browser'] ?? [];
            foreach ($projects as $path => $project) {
                $version = $project['requirements']['@playwright/test'] ?? null;
                if ($version !== null && ltrim($version, '^~') !== ($browser['playwrightVersion'] ?? null)) {
                    $report->add('finding', $path, 'browser-version', 'Playwright package differs from the configured browser image version.', ['declared' => $version, 'browser' => $browser]);
                }
            }
        }
        return $projects;
    }

    /** @param array<string,mixed> $policy */
    private static function validatePolicy(array $policy): void
    {
        if (($policy['version'] ?? null) !== 1) {
            throw new \RuntimeException('Unsupported JavaScript dependency policy version.');
        }
        foreach (['scopeDirectories', 'projects', 'exceptions', 'patches', 'resolutions'] as $field) {
            if (!isset($policy[$field]) || !is_array($policy[$field])) {
                throw new \RuntimeException('Missing or malformed JavaScript dependency policy field: ' . $field);
            }
        }
        if (
            !self::stringList($policy['scopeDirectories'])
            || $policy['scopeDirectories'] === []
            || $policy['projects'] === []
            || array_is_list($policy['projects'])
        ) {
            throw new \RuntimeException('Dependency policy requires source directories and classified projects.');
        }
        foreach ($policy['scopeDirectories'] as $directory) {
            if (!self::relativePath($directory)) {
                throw new \RuntimeException('Policy scope directory must be repository relative.');
            }
        }
        foreach ($policy['projects'] as $path => $project) {
            if (
                !self::relativePath($path)
                || !is_array($project)
                || !in_array($project['kind'] ?? null, ['locked', 'manifest-only', 'dependency-free'], true)
            ) {
                throw new \RuntimeException('Malformed dependency project classification.');
            }
            if ($project['kind'] === 'locked') {
                if (!in_array($project['manager'] ?? null, ['yarn', 'npm'], true) || !self::relativePath($project['lock'] ?? null)) {
                    throw new \RuntimeException('Locked project requires a supported manager and owned lock path.');
                }
            } elseif (!is_string($project['reason'] ?? null) || trim($project['reason']) === '') {
                throw new \RuntimeException('Unlocked project classification requires a reason.');
            }
        }
        if (!array_is_list($policy['exceptions']) || !array_is_list($policy['patches'])) {
            throw new \RuntimeException('Policy exceptions and patches must be lists.');
        }
        foreach ($policy['exceptions'] as $exception) {
            if (
                !is_array($exception)
                || !is_string($exception['package'] ?? null)
                || $exception['package'] === ''
                || !self::stringList($exception['projects'] ?? null)
                || $exception['projects'] === []
                || !self::stringList($exception['declared'] ?? null)
                || $exception['declared'] === []
                || !is_string($exception['reason'] ?? null)
                || trim($exception['reason']) === ''
            ) {
                throw new \RuntimeException('Malformed retained-version exception.');
            }
            foreach ($exception['projects'] as $project) {
                if (!isset($policy['projects'][$project])) {
                    throw new \RuntimeException('Exception references an unclassified project.');
                }
            }
        }
        foreach ($policy['patches'] as $patch) {
            if (
                !is_array($patch)
                || !is_string($patch['project'] ?? null)
                || !isset($policy['projects'][$patch['project']])
                || !self::relativePath($patch['path'] ?? null)
                || !is_string($patch['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $patch['sha256']) !== 1
                || !is_string($patch['reason'] ?? null)
                || trim($patch['reason']) === ''
            ) {
                throw new \RuntimeException('Malformed authored-patch policy.');
            }
        }
        foreach ($policy['resolutions'] as $project => $resolutions) {
            if (!isset($policy['projects'][$project]) || !is_array($resolutions)) {
                throw new \RuntimeException('Malformed resolution ownership policy.');
            }
            foreach ($resolutions as $selector => $resolution) {
                if (
                    !is_string($selector)
                    || $selector === ''
                    || !is_array($resolution)
                    || !is_string($resolution['value'] ?? null)
                    || $resolution['value'] === ''
                    || !is_string($resolution['reason'] ?? null)
                    || trim($resolution['reason']) === ''
                ) {
                    throw new \RuntimeException('Resolution policy requires a selector, value and rationale.');
                }
            }
        }
    }

    private static function stringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_all($value, static fn(mixed $item): bool => is_string($item) && trim($item) !== '');
    }

    private static function relativePath(mixed $path): bool
    {
        if (!is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        return explode('/', $path)
            |> (static fn(array $segments): bool => array_intersect($segments, ['', '.', '..']) === []);
    }

    /** @return \Generator<string> */
    private static function manifests(string $directory): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) {
                continue;
            }
            if ($entry->isDir() && !in_array($entry->getFilename(), ['node_modules', '.yarn', '.git', 'dist', 'build', 'coverage', '.next', '.vercel'], true)) {
                yield from self::manifests($entry->getPathname());
            } elseif ($entry->isFile() && $entry->getFilename() === 'package.json') {
                yield $entry->getPathname();
            }
        }
    }

    /** @param array<string,mixed> $policy */
    private static function patches(string $root, string $project, array $policy, Report $report): void
    {
        foreach (glob($root . '/' . dirname($project) . '/.yarn/patches/*.patch') ?: [] as $file) {
            $path = substr($file, strlen($root) + 1);
            $owned = array_filter($policy['patches'], static fn(array $patch): bool => ($patch['path'] ?? null) === $path)
                |> array_values(...);
            $valid = count($owned) === 1 && ($owned[0]['sha256'] ?? '') === hash_file('sha256', $file) && !empty($owned[0]['reason']);
            $report->add($valid ? 'evidence' : 'finding', $project, 'authored-patch', $valid ? 'Authored patch hash and rationale match policy.' : 'Authored patch is unowned, changed or lacks a rationale.', ['path' => $path]);
        }
        foreach ($policy['patches'] as $patch) {
            if (($patch['project'] ?? null) === $project && !is_file($root . '/' . $patch['path'])) {
                $report->add('finding', $project, 'authored-patch', 'Policy-owned authored patch is missing.', $patch);
            }
        }
    }
}
