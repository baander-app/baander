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
                        if (($locked['packages']['']['devDependencies'] ?? []) !== ($manifest['devDependencies'] ?? []) || ($locked['packages']['']['dependencies'] ?? []) !== ($manifest['dependencies'] ?? [])) {
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
            $owned = array_values(array_filter($policy['patches'], static fn(array $patch): bool => ($patch['path'] ?? null) === $path));
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
