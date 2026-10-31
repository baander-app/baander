<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

final class LocalEvidence
{
    /**
     * @return array<string,list<array<string,mixed>>>
     */
    public static function collect(string $root, Inventory $inventory, Report $report): array
    {
        foreach ($inventory->policy['excludedTrees'] ?? [] as $path => $classification) {
            $report->add('evidence', $path, 'inventory-exclusion', 'policy', $classification['reason'], ['kind' => $classification['kind']]);
        }
        foreach ($inventory->gaps as $gap) {
            $report->add('gap', $gap['path'], 'inventory', 'policy', $gap['reason']);
        }
        foreach ($inventory->projects as $path => $project) {
            $report->add('evidence', $path, 'manifest', 'policy', 'Classified direct production and development requirements.', ['kind' => $project['classification']['kind'], 'requirements' => $project['requirements']]);
            if ($project['lock'] !== null) {
                $report->add('evidence', $path, 'lock', $project['classification']['lock'], 'Read locked production and development packages.', ['packages' => [...$project['lock']['packages'], ...$project['lock']['packages-dev']]]);
            }
            $installed = $root . '/' . dirname($path) . '/vendor/composer/installed.json';
            $report->add('evidence', $path, 'installed-state', $path, is_file($installed) ? 'Installed metadata exists; installed-state comparison is outside requested coverage.' : 'Installed metadata unavailable; lock inventory remains authoritative.');
        }
        $runtimes = self::runtimes($root, $inventory->policy['runtimes'] ?? [], $report);
        self::patches($root, $inventory, $report);
        self::forks($root, $inventory, $report);
        return $runtimes;
    }

    /**
     * @param array<string,mixed> $definitions
     * @return array<string,list<array<string,mixed>>>
     */
    private static function runtimes(string $root, array $definitions, Report $report): array
    {
        $result = [];
        foreach ($definitions as $name => $runtime) {
            $result[$name] = [];
            foreach ($runtime['declarations'] ?? [] as $declaration) {
                $path = $declaration['path'] ?? '';
                try {
                    Inventory::path($path);
                    $version = self::declaration($root . '/' . $path, $declaration);
                    $record = ['path' => $path, 'kind' => $declaration['kind'], 'version' => $version, ...self::recipeMetadata($root . '/' . $path, $name, $declaration)];
                    $result[$name][] = $record;
                    $report->add('evidence', $path, 'runtime-' . $name, 'source', 'Read explicit runtime declaration.', $record);
                    if (isset($runtime['minimum']) && version_compare($version, $runtime['minimum'], '<')) {
                        $report->add('finding', $path, 'runtime-' . $name, 'policy', 'Runtime is below the supported minimum.', ['version' => $version, 'minimum' => $runtime['minimum']]);
                    }
                } catch (\Throwable $error) {
                    $report->add('gap', $path, 'runtime-' . $name, 'source', $error->getMessage());
                }
            }
            $hashes = [];
            foreach ($result[$name] as $declaration) {
                if (isset($declaration['archiveSha256'])) {
                    $hashes[$declaration['version']][] = $declaration['archiveSha256'];
                }
            }
            foreach ($hashes as $version => $digests) {
                if (count(array_unique($digests)) > 1) {
                    $report->add('finding', $name, 'runtime-archive', 'policy', 'Equivalent runtime archive declarations have different hashes.', ['version' => $version, 'sha256' => $digests]);
                }
            }
            $versions = array_unique(array_column($result[$name], 'version'));
            if (count($versions) > 1) {
                $report->add('finding', $name, 'runtime-agreement', 'policy', 'Declared runtime versions disagree.', ['declarations' => $result[$name]]);
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $declaration
     */
    public static function declaration(string $file, array $declaration): string
    {
        $text = @file_get_contents($file);
        if ($text === false) {
            throw new \RuntimeException('Cannot read runtime declaration.');
        }
        if (($declaration['kind'] ?? '') === 'json') {
            $value = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            foreach (explode('/', ltrim($declaration['pointer'], '/')) as $part) {
                $key = str_replace(['~1', '~0'], ['/', '~'], $part);
                $value = is_array($value) ? ($value[$key] ?? null) : null;
            }
            if (!is_string($value) || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $value, $match)) {
                throw new \RuntimeException('Unsupported JSON runtime declaration.');
            }
            return $match[1];
        }
        $key = preg_quote($declaration['key'] ?? '', '/');
        $pattern = match ($declaration['kind'] ?? '') {
            'assignment' => '/^(?:ARG\s+)?' . $key . '=["\x27]?(\d+\.\d+\.\d+)["\x27]?\s*$/m',
            'alpine-package' => '/^ARG\s+' . $key . '=(\d+\.\d+\.\d+)-r\d+\s*$/m',
            'php-image' => '/^FROM\s+php:(\d+\.\d+\.\d+)-[^\s]+/m',
            'composer-image' => '/(?:--from=|FROM\s+)composer:(\d+\.\d+\.\d+)(?:@sha256:[a-f0-9]{64})(?=\s)/m',
            'swoole-install' => '/\bpie\s+install\s+swoole\/swoole:(\d+\.\d+\.\d+)\b/',
            default => throw new \RuntimeException('Unsupported runtime declaration kind.'),
        };
        if (preg_match_all($pattern, $text, $matches) !== 1) {
            throw new \RuntimeException('Runtime declaration must have exactly one supported match.');
        }
        return $matches[1][0];
    }

    /**
     * @param array<string,mixed> $declaration
     * @return array<string,mixed>
     */
    private static function recipeMetadata(string $file, string $runtime, array $declaration): array
    {
        $text = file_get_contents($file);
        if ($text === false || $declaration['kind'] === 'json') {
            return [];
        }
        $metadata = [];
        $hashKey = match ($declaration['key'] ?? '') {
            'PHP_VERSION' => 'PHP_SHA256',
            'SWOOLE_VERSION' => 'SWOOLE_SHA256',
            'version' => 'checksum',
            'php_version' => 'php_checksum',
            'swoole_version' => 'swoole_checksum',
            default => null,
        };
        if ($hashKey !== null && preg_match_all('/^(?:ARG\\s+)?' . $hashKey . '=/m', $text, $assignments) > 0) {
            if (count($assignments[0]) !== 1 || preg_match_all('/^(?:ARG\\s+)?' . $hashKey . '=([a-f0-9]{64})\\s*$/m', $text, $matches) !== 1) {
                throw new \RuntimeException('Runtime archive hash declaration must have exactly one supported match.');
            }
            $metadata['archiveSha256'] = $matches[1][0];
        }
        if (preg_match_all('/https:\\/\\/[^\\s"\\x27]+(?:tar\\.xz|tgz)/', $text, $archives)) {
            $metadata['archives'] = array_values(array_filter($archives[0], static fn(string $url): bool => str_contains($url, $runtime)));
        }
        if ($runtime === 'php') {
            if ($declaration['kind'] === 'php-image' && preg_match('/^FROM\\s+php:[^\\s]+/m', $text, $image)) {
                $metadata['image'] = $image[0];
                $metadata['mode'] = str_contains($image[0], '-zts-') ? 'zts' : 'nts';
            } elseif (str_contains($text, './configure')) {
                $metadata['mode'] = str_contains($text, '--enable-zts') ? 'zts' : 'nts';
            }
        }
        if ($declaration['kind'] === 'composer-image' && preg_match('/composer:[^\\s]+@sha256:([a-f0-9]{64})/', $text, $digest)) {
            $metadata['imageSha256'] = $digest[1];
        }
        return $metadata;
    }

    private static function forks(string $root, Inventory $inventory, Report $report): void
    {
        foreach ($inventory->policy['forks'] ?? [] as $package => $fork) {
            try {
                $active = false;
                foreach ($inventory->projects as $project) {
                    foreach ($project['requirements'] as $requirement) {
                        $active = $active || $requirement['name'] === $package;
                    }
                }
                if (!$active) {
                    $report->add('finding', $package, 'fork', 'policy', 'Inactive fork remains classified as an active local fix.');
                    continue;
                }
                $valid = !empty($fork['proofs']) && !empty($fork['reason']);
                foreach ($fork['proofs'] ?? [] as $proof) {
                    Inventory::path($proof);
                    $valid = $valid && is_file($root . '/' . $proof);
                }
                if (!is_string($fork['comparison'] ?? null)) {
                    throw new \RuntimeException('Active forks require a reviewed source comparison inventory.');
                }
                Inventory::path($fork['comparison']);
                $comparison = Inventory::json($root . '/' . $fork['comparison']);
                $valid = $valid && ($comparison['reference'] ?? null) === ($fork['reviewedReference'] ?? null)
                    && ($comparison['tag'] ?? null) === ($fork['reviewedVersion'] ?? null);
                ForkEvidence::collect($root, $package, $fork, $comparison, $report);
                $report->add($valid ? 'evidence' : 'finding', $package, 'fork', 'policy', $valid ? 'Fork comparison and regression ownership agree.' : 'Fork comparison or regression ownership differs.');
            } catch (\Throwable $error) {
                $report->add('gap', $package, 'fork', 'policy', $error->getMessage());
            }
        }
    }

    private static function patches(string $root, Inventory $inventory, Report $report): void
    {
        $activePolicy = [];
        foreach ($inventory->projects as $path => $project) {
            $definitions = $project['manifest']['extra']['patches'] ?? [];
            $lockPath = (dirname($path) === '.' ? '' : dirname($path) . '/') . 'patches.lock.json';
            if ($definitions === [] && !is_file($root . '/' . $lockPath)) {
                continue;
            }
            try {
                $lock = Inventory::json($root . '/' . $lockPath);
                if (!is_array($lock['patches'] ?? null) || !is_string($lock['_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $lock['_hash'])) {
                    throw new \RuntimeException('Unsupported patch lock metadata.');
                }
                $active = [];
                foreach ($definitions as $package => $patches) {
                    foreach ($patches as $description => $definition) {
                        $url = is_string($definition) ? $definition : ($definition['url'] ?? '');
                        $declaredHash = is_array($definition) ? ($definition['sha256'] ?? null) : null;
                        if (is_array($definition) && array_key_exists('sha256', $definition) && (!is_string($declaredHash) || preg_match('/^[a-f0-9]{64}$/Di', $declaredHash) !== 1)) {
                            throw new \RuntimeException('Expanded patch definition SHA256 must contain exactly 64 hexadecimal characters.');
                        }
                        $declaredHash = $declaredHash === null ? null : strtolower($declaredHash);
                        $activePolicy[$url] = true;
                        $active[$package . ':' . $url] = ['package' => $package, 'description' => is_string($description) ? $description : ($definition['description'] ?? ''), 'url' => $url, 'sha256' => $declaredHash, 'depth' => (is_array($definition) ? ($definition['depth'] ?? null) : null) ?? $project['manifest']['extra']['composer-patches']['package-depths'][$package] ?? $project['manifest']['extra']['composer-patches']['default-patch-depth'] ?? 1];
                    }
                }
                foreach ($lock['patches'] as $package => $patches) {
                    foreach ($patches as $patch) {
                        $key = $package . ':' . ($patch['url'] ?? '');
                        $definition = $active[$key] ?? null;
                        if ($definition === null) {
                            $report->add('finding', $path, 'patch', $lockPath, 'Stale patch lock entry.', ['patch' => $patch]);
                            continue;
                        }
                        unset($active[$key]);
                        $url = $definition['url'];
                        Inventory::path($url);
                        $policy = $inventory->policy['patches'][$url] ?? null;
                        $lockedPackages = [...($project['lock']['packages'] ?? []), ...($project['lock']['packages-dev'] ?? [])];
                        $target = array_filter($lockedPackages, static fn(array $entry): bool => ($entry['name'] ?? '') === $package);
                        $proofsValid = is_array($policy) && !empty($policy['proofs']);
                        foreach ($policy['proofs'] ?? [] as $proof) {
                            Inventory::path($proof);
                            $proofsValid = $proofsValid && is_file($root . '/' . $proof);
                        }
                        $patchFile = $root . '/' . (dirname($path) === '.' ? '' : dirname($path) . '/') . $url;
                        $fileHash = is_file($patchFile) ? hash_file('sha256', $patchFile) : null;
                        $declaredHash = $definition['sha256'];
                        $valid = is_file($patchFile) && $fileHash === ($patch['sha256'] ?? null)
                            && ($declaredHash === null || $declaredHash === $fileHash && $declaredHash === ($patch['sha256'] ?? null))
                            && ($patch['package'] ?? null) === $package && ($patch['description'] ?? null) === $definition['description']
                            && ($patch['depth'] ?? null) === $definition['depth'] && ($policy['package'] ?? null) === $package && $proofsValid && $target !== [];
                        $report->add($valid ? 'evidence' : 'finding', $path, 'patch', $url, $valid ? 'Patch target, hash, depth and proof ownership agree.' : 'Patch target, hash, depth or proof ownership differs.', ['package' => $package, 'depth' => $patch['depth'] ?? null, 'declaredSha256' => $declaredHash, 'lockedSha256' => $patch['sha256'] ?? null, 'fileSha256' => $fileHash]);
                    }
                }
                foreach ($active as $patch) {
                    $report->add('finding', $path, 'patch', $lockPath, 'Active patch is missing from the patch lock.', $patch);
                }
            } catch (\Throwable $error) {
                $report->add('gap', $path, 'patch', $lockPath, $error->getMessage());
            }
        }
        foreach ($inventory->policy['patches'] ?? [] as $patch => $policy) {
            if (!isset($activePolicy[$patch])) {
                $report->add('finding', $patch, 'patch', 'policy', 'Inactive patch remains classified as an active local fix.');
            }
        }
    }
}
