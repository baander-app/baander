<?php

declare(strict_types=1);

namespace Baander\Tooling\Inventory;

use Baander\Tooling\Configuration\ApplicationLayout;

final class ApplicationInventory
{
    public const string NATIVE = '/usr/share/baander-build-evidence';
    public const string SCHEMA = 'baander-application-packaged-inventory/v1';

    public static function fileHash(string $path): string
    {
        return hash_file('sha256', $path) ?: throw new InventoryError('Cannot hash file: ' . $path);
    }

    public static function rootPath(string $root, string $name): string
    {
        $pending = explode('/', ltrim($name, '/'));
        $parts = [];
        $links = 0;
        while ($pending !== []) {
            $part = array_shift($pending);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new InventoryError('path escapes exported filesystem: ' . $name);
                }
                array_pop($parts);
                continue;
            }
            $candidate = $root . '/' . implode('/', [...$parts, $part]);
            if (is_link($candidate)) {
                if (++$links > 40) {
                    throw new InventoryError('image symlink loop: ' . $name);
                }
                $target = readlink($candidate);
                if ($target === false) {
                    throw new InventoryError('Cannot read image symlink: ' . $name);
                }
                if (str_starts_with($target, '/')) {
                    $parts = [];
                }
                $pending = [...explode('/', ltrim($target, '/')), ...$pending];
            } else {
                $parts[] = $part;
            }
        }
        return rtrim($root, '/') . '/' . implode('/', $parts);
    }

    public static function rootFile(string $root, string $name): string
    {
        $path = self::rootPath($root, $name);
        if (!is_file($path)) {
            throw new MissingImageFileError('missing image file: ' . $name);
        }
        return $path;
    }

    /** @return array<string, mixed> */
    public static function imageIdentity(mixed $inspect, string $target): array
    {
        $value = is_array($inspect) && array_is_list($inspect) && count($inspect) === 1 ? $inspect[0] : $inspect;
        $platform = $value['Os'] . '/' . $value['Architecture'];
        if (!empty($value['Variant'])) {
            $platform .= '/' . $value['Variant'];
        }
        return [
            'image_id' => $value['Id'],
            'target' => $target,
            'platform' => $platform,
            'revision' => $value['Config']['Labels']['org.opencontainers.image.revision'] ?? '',
            'application_layout' => ApplicationLayout::fromImage($value)->toArray(),
        ];
    }

    /** @param array<string, mixed> $identity */
    public static function checkIdentity(array $identity, string $revision): void
    {
        ApplicationLayout::fromArray($identity['application_layout'] ?? []);
        foreach (['image_id', 'target', 'platform', 'revision'] as $key) {
            if (empty($identity[$key])) {
                throw new InventoryError('image identity, platform, target and observed revision are required');
            }
        }
        if (!in_array($identity['target'], ['production', 'worker'], true)) {
            throw new InventoryError('unsupported application target');
        }
        if ($identity['revision'] !== $revision) {
            throw new InventoryError('observed image revision differs from expected revision');
        }
    }

    /** @return list<array<string, string>> */
    public static function debianStatus(string $text): array
    {
        $packages = [];
        foreach (explode("\n\n", $text) as $paragraph) {
            $fields = [];
            $key = null;
            foreach (explode("\n", $paragraph) as $line) {
                if (preg_match('/^[ \t]/', $line) && $key !== null) {
                    $fields[$key] .= "\n" . $line;
                } elseif (str_contains($line, ': ')) {
                    [$key, $value] = explode(': ', $line, 2);
                    $fields[$key] = $value;
                }
            }
            if (($fields['Status'] ?? '') === 'install ok installed') {
                foreach (['Package', 'Version', 'Architecture'] as $required) {
                    if (empty($fields[$required])) {
                        throw new InventoryError('incomplete installed Debian package metadata');
                    }
                }
                $packages[] = $fields;
            }
        }
        if ($packages === []) {
            throw new InventoryError('no installed Debian packages observed');
        }
        usort(
            $packages,
            static fn(array $a, array $b): int => [$a['Package'], $a['Architecture']] <=> [$b['Package'], $b['Architecture']],
        );
        return $packages;
    }

    private static function known(mixed $value): bool
    {
        return $value !== null && !in_array(
            (string) $value |> trim(...) |> strtolower(...),
            ['', 'unknown', 'noassertion', 'n/a'],
            true,
        );
    }

    private static function noticeName(string $name): bool
    {
        return preg_match('/^(license|licence|copying|copyright|notice|authors)([._-].*)?$/i', $name) === 1;
    }

    private static function fileKind(string $path, string $local): string
    {
        $stream = fopen($local, 'rb');
        if ($stream === false) {
            throw new InventoryError('Cannot read image file: ' . $path);
        }
        $magic = fread($stream, 8);
        fclose($stream);
        $magic = $magic ?: '';
        $name = basename($path);
        if (str_starts_with($magic, "\x7fELF")) {
            return 'native';
        }
        if (str_starts_with($magic, "\0asm") || str_ends_with($name, '.wasm')) {
            return 'wasm';
        }
        foreach (["!<arch>\n", "PK\x03\x04", "\x1f\x8b", "\xfd7zXZ\0"] as $prefix) {
            if (str_starts_with($magic, $prefix)) {
                return 'archive';
            }
        }
        if (preg_match('/\.(tar|phar|zip|a|deb|7z|bz2)$/', $name)) {
            return 'archive';
        }
        if (in_array(
            $name,
            [
                'package.json',
                'composer.json',
                'composer.lock',
                'package-lock.json',
                'pnpm-lock.yaml',
                'yarn.lock',
                'installed.json',
            ],
            true,
        )) {
            return 'manifest';
        }
        if (self::noticeName($name)) {
            return 'notice';
        }
        if (preg_match('#^(/usr/local/bin/|/usr/local/sbin/|/opt/)#', $path) || str_starts_with($magic, '#!')) {
            return 'tool-or-script';
        }
        return 'file';
    }

    /** @return array<string,array<string,mixed>> */
    private static function specialEntries(string $root): array
    {
        $path = $root . '.archive-metadata.json';
        if (!file_exists($path)) {
            return [];
        }
        $entries = \Baander\Tooling\Filesystem\AtomicFile::readJson($path);
        foreach ($entries as $name => $entry) {
            if (!is_string($name) || !str_starts_with($name, '/') || self::normalize($name) !== $name || !is_array($entry)
                || count($entry) !== 4 || !in_array($entry['archive_type'] ?? null, ['character', 'block', 'fifo'], true)
                || !is_int($entry['mode'] ?? null) || $entry['mode'] < 0 || $entry['mode'] > 0777
                || !is_int($entry['device_major'] ?? null) || $entry['device_major'] < 0
                || !is_int($entry['device_minor'] ?? null) || $entry['device_minor'] < 0) {
                throw new InventoryError('Invalid archive metadata');
            }
        }
        return $entries;
    }

    /** @return list<string> */
    public static function files(string $root): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (!$file->isDir() || $file->isLink()) {
                $paths[] = substr($file->getPathname(), strlen(rtrim($root, '/')));
            }
        }
        $paths = array_values(array_unique([...$paths, ...array_keys(self::specialEntries($root))]));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @param array<string, mixed> $identity
     * @return array{array<string, mixed>, list<string>}
     */
    public static function observe(string $root, array $identity): array
    {
        $app = ApplicationLayout::fromArray($identity['application_layout'] ?? [])->root;
        $special = self::specialEntries($root);
        $gaps = [];
        $evidence = [];
        $gap = static function (string $path, string $reason, ?string $component = null) use (&$gaps): void {
            $value = ['path' => $path, 'reason' => $reason];
            if ($component !== null && $component !== '') {
                $value['component'] = $component;
            }
            $gaps[] = $value;
        };
        $notice = static function (string $path, string $component) use ($root, &$evidence, $gap): void {
            try {
                self::rootFile($root, $path);
            } catch (MissingImageFileError) {
                $gap($path, 'missing license or copyright evidence', $component);
                return;
            }
            $evidence[$path] = true;
        };
        $status = '/var/lib/dpkg/status';
        $evidence[$status] = true;
        $packages = [];
        $owners = [];
        foreach (self::debianStatus((string) file_get_contents(self::rootFile($root, $status))) as $package) {
            $name = $package['Package'];
            $architecture = $package['Architecture'];
            $component = 'deb:' . $name . ':' . $architecture;
            $record = [
                'id' => $component,
                'name' => $name,
                'version' => $package['Version'],
                'architecture' => $architecture,
                'source' => $package['Source'] ?? $name,
                'declared_license' => null,
            ];
            $list = '/var/lib/dpkg/info/' . $name . ':' . $architecture . '.list';
            if (!is_file($root . $list)) {
                $list = '/var/lib/dpkg/info/' . $name . '.list';
            }
            try {
                $entries = preg_split('/\R/', (string) file_get_contents(self::rootFile($root, $list))) ?: [];
                $evidence[$list] = true;
            } catch (MissingImageFileError) {
                $entries = [];
                $gap($list, 'installed package has no ownership list', $component);
            }
            foreach ($entries as $path) {
                $owners[$path][] = $component;
            }
            $copyright = '/usr/share/doc/' . $name . '/copyright';
            $notice($copyright, $component);
            if (isset($evidence[$copyright])) {
                $text = (string) file_get_contents(self::rootFile($root, $copyright));
                preg_match_all('/^License:\s*(.+)/m', $text, $matches);
                $licenses = $matches[1] |> array_unique(...) |> array_values(...);
                sort($licenses, SORT_STRING);
                $record['declared_license'] = $licenses ?: null;
                if ($licenses === []) {
                    $gap($copyright, 'Debian copyright has no machine-readable license declaration', $component);
                }
                preg_match_all('#/usr/share/common-licenses/[A-Za-z0-9_.+\-]+#', $text, $matches);
                foreach (array_unique($matches[0]) as $common) {
                    $notice(rtrim($common, '.'), $component);
                }
            }
            $packages[] = $record;
        }
        $installedPath = $app . '/vendor/composer/installed.json';
        $installed = Json::read(self::rootFile($root, $installedPath));
        $evidence[$installedPath] = true;
        if (is_array($installed) && !array_is_list($installed)) {
            $installed = $installed['packages'] ?? null;
        }
        if (!is_array($installed) || !array_is_list($installed)) {
            throw new InventoryError('unusable installed Composer package metadata');
        }
        $composer = [];
        $composerRoots = [];
        foreach ($installed as $package) {
            $name = $package['name'];
            $type = $package['type'] ?? null;
            $full = null;
            $component = 'composer:' . $name;
            if ($type !== 'metapackage') {
                $install = $package['install-path'] ?? null;
                if (!is_string($install) || $install === '') {
                    throw new InventoryError('missing Composer installation path: ' . $name);
                }
                $full = self::normalize($app . '/vendor/composer/' . $install);
                if (!str_starts_with($full, $app . '/')) {
                    throw new InventoryError('Composer installation path escapes application: ' . $name);
                }
                $composerRoots[] = [$full, $component];
            }
            $record = [
                'id' => $component,
                'name' => $name,
                'type' => $type,
                'version' => $package['version'] ?? null,
                'source' => $package['source'] ?? null,
                'dist' => $package['dist'] ?? null,
                'declared_license' => $package['license'] ?? null,
                'install_path' => $full,
            ];
            $composer[] = $record;
            if (empty($record['declared_license']) || !array_all($record['declared_license'], self::known(...))) {
                $gap($installedPath, 'missing declared Composer license', $component);
            }
            if ($type === 'metapackage') {
                continue;
            }
            $directory = self::rootPath($root, $full);
            $found = [];
            if (is_dir($directory)) {
                $physical = substr($directory, strlen(rtrim($root, '/')));
                if ($physical !== $full) {
                    $composerRoots[] = [$physical, $component];
                }
                foreach (scandir($directory) ?: [] as $entry) {
                    $local = $directory . '/' . $entry;
                    if (self::noticeName($entry) && (is_file($local) || is_link($local))) {
                        $found[] = $physical . '/' . $entry;
                    }
                }
            }
            if ($found === []) {
                $gap($full, 'installed Composer package has no retained top-level notice', $component);
            }
            foreach ($found as $path) {
                $notice($path, $component);
            }
        }
        usort($composerRoots, static fn(array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
        $native = [];
        $nativeOwners = [];
        $nativePath = self::NATIVE . '/components.json';
        if (is_file($root . $nativePath)) {
            $data = Json::read(self::rootFile($root, $nativePath));
            if (($data['schema'] ?? null) !== 'baander-native-build-evidence/v1' || !is_array($data['components'] ?? null)) {
                throw new InventoryError('unusable native build evidence');
            }
            $evidence[$nativePath] = true;
            $native = $data['components'];
            foreach ($native as $component) {
                $key = $component['id'];
                $source = (array) ($component['source'] ?? []);
                if (
                    !self::known($component['version'] ?? null)
                    || !($source
                        |> (static fn(array $fields): array => array_intersect_key($fields, array_flip(['url', 'revision', 'sha256'])))
                        |> (static fn(array $fields): bool => array_any($fields, self::known(...))))
                ) {
                    $gap($nativePath, 'native source identity or version incomplete', $key);
                }
                $artifacts = $component['artifacts'] ?? [];
                if ($artifacts === []) {
                    $gap($nativePath, 'native component has no bound final output', $key);
                }
                foreach ($artifacts as $artifact) {
                    $path = $artifact['path'];
                    if (!str_starts_with($path, '/') || self::fileHash(self::rootFile($root, $path)) !== $artifact['sha256']) {
                        throw new InventoryError('native output differs from build evidence: ' . $path);
                    }
                    $nativeOwners[$path][] = $key;
                }
                if (empty($component['notices'])) {
                    $gap($nativePath, 'native component has no upstream notices', $key);
                }
                foreach ($component['notices'] ?? [] as $path) {
                    $notice($path, $key);
                }
                $metadata = (array) ($component['metadata'] ?? []);
                if (!empty($metadata['own_notices_gap'])) {
                    $gap($nativePath, $metadata['own_notices_gap'], $key);
                }
                foreach ($metadata['unmatched_artifact_patterns'] ?? [] as $pattern) {
                    $gap($pattern, 'native build artifact pattern matched no final files', $key);
                }
                if (($component['embedded_dependencies'] ?? null) !== 'reviewed') {
                    $gap($nativePath, 'embedded native dependency coverage requires review', $key);
                }
            }
        } else {
            $gap($nativePath, 'native build provenance is absent');
        }
        $files = [];
        $manifests = [];
        foreach (self::files($root) as $path) {
            $local = $root . $path;
            $name = basename($path);
            $entry = ['path' => $path];
            if (isset($special[$path])) {
                if (file_exists($local) || is_link($local)) {
                    throw new InventoryError('Archive metadata conflicts with exported file: ' . $path);
                }
                $entry += ['type' => 'special', 'kind' => 'special', ...$special[$path]];
            } elseif (is_link($local)) {
                $entry += ['type' => 'symlink', 'target' => readlink($local)];
                try {
                    $resolved = self::rootFile($root, $path);
                } catch (MissingImageFileError) {
                    $resolved = null;
                }
                if ($resolved !== null) {
                    $entry += [
                        'sha256' => self::fileHash($resolved),
                        'size' => filesize($resolved),
                        'kind' => self::fileKind($path, $resolved),
                    ];
                } else {
                    $entry['kind'] = 'symlink';
                }
            } elseif (is_file($local)) {
                $entry += [
                    'type' => 'file',
                    'sha256' => self::fileHash($local),
                    'size' => filesize($local),
                    'kind' => self::fileKind($path, $local),
                ];
            } else {
                $entry += ['type' => 'special', 'kind' => 'special'];
            }
            $group = 'unresolved';
            $assigned = $owners[$path] ?? [];
            if ($assigned !== []) {
                $group = 'debian';
            } elseif ($path === self::NATIVE || str_starts_with($path, self::NATIVE . '/')) {
                $group = 'build-evidence';
            } else {
                foreach ($composerRoots as [$prefix, $component]) {
                    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                        $group = 'installed-composer';
                        $assigned = [$component];
                        break;
                    }
                }
                if ($group === 'unresolved' && str_starts_with($path, $app . '/')) {
                    $group = 'copied-repository';
                }
                if (isset($nativeOwners[$path])) {
                    $group = 'native-build';
                    $assigned = $nativeOwners[$path];
                }
            }
            sort($assigned, SORT_STRING);
            $entry += ['group' => $group, 'owners' => $assigned];
            $files[] = $entry;
            $kind = $entry['kind'];
            if (
                ($group === 'build-evidence' && isset($entry['sha256']))
                || $kind === 'notice'
                || (str_starts_with($path, $app . '/third_party/inventory/')
                    && $name === 'source.json'
                    && isset($entry['sha256']))
            ) {
                $evidence[$path] = true;
            }
            if (in_array($group, ['unresolved', 'copied-repository'], true) && in_array($kind, ['native', 'wasm', 'archive', 'tool-or-script'], true)) {
                $gap($path, 'unresolved ownership or embedded dependency evidence for ' . $kind);
            }
            if ($group === 'copied-repository' && in_array($name, ['composer.json', 'package.json'], true) && isset($entry['sha256'])) {
                try {
                    $manifest = Json::read(self::rootFile($root, $path));
                    if (!($manifest instanceof \stdClass) && (!is_array($manifest) || array_is_list($manifest))) {
                        throw new \JsonException('manifest must contain an object');
                    }
                    $manifest = (array) $manifest;
                    $manifests[] = [
                        'path' => $path,
                        'name' => $manifest['name'] ?? null,
                        'version' => $manifest['version'] ?? null,
                        'declared_license' => $manifest['license'] ?? null,
                    ];
                    $evidence[$path] = true;
                } catch (\JsonException) {
                    $gap($path, 'copied package manifest is not usable JSON metadata');
                }
            }
            if ($group === 'copied-repository' && $kind === 'manifest') {
                $gap($path, 'copied manifest describes source requirements; installed or generated contents require review');
            }
            if ($group === 'unresolved' && $kind === 'file') {
                $gap($path, 'unowned filesystem material requires classification');
            }
        }
        $byResolved = [];
        foreach (['debian' => $owners, 'native-build' => $nativeOwners] as $group => $ownership) {
            foreach ($ownership as $path => $assigned) {
                try {
                    $resolved = self::rootFile($root, $path);
                } catch (MissingImageFileError) {
                    continue;
                }
                $byResolved[$group][$resolved] = [...($byResolved[$group][$resolved] ?? []), ...$assigned]
                    |> array_unique(...)
                    |> array_values(...);
            }
        }
        $aliases = [];
        foreach ($files as &$entry) {
            if ($entry['group'] === 'unresolved' && isset($entry['sha256'])) {
                $resolved = self::rootFile($root, $entry['path']);
                foreach (['debian', 'native-build'] as $group) {
                    if (!empty($byResolved[$group][$resolved])) {
                        $entry['group'] = $group;
                        $entry['owners'] = $byResolved[$group][$resolved];
                        sort($entry['owners'], SORT_STRING);
                        $aliases[$entry['path']] = true;
                        break;
                    }
                }
            }
        }
        unset($entry);
        $gaps = $gaps
            |> (static fn(array $entries): array => array_filter(
                $entries,
                static fn(array $gap): bool => !(isset($aliases[$gap['path']]) && (
                    str_starts_with($gap['reason'], 'unresolved ownership or embedded dependency evidence for ')
                    || $gap['reason'] === 'unowned filesystem material requires classification'
                )),
            ))
            |> array_values(...);
        usort(
            $gaps,
            static fn(array $a, array $b): int => [$a['path'], $a['component'] ?? '', $a['reason']] <=> [$b['path'], $b['component'] ?? '', $b['reason']],
        );
        usort($composer, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);
        return [
            [
                'schema' => self::SCHEMA,
                'identity' => $identity,
                'debian' => $packages,
                'composer' => $composer,
                'native' => $native,
                'files' => $files,
                'copied_manifests' => $manifests,
                'review_gaps' => $gaps,
                'redistribution_review' => 'not-qualified',
            ],
            array_keys($evidence),
        ];
    }

    public static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return '/' . implode('/', $parts);
    }

    /** @param list<string> $paths
     * @return list<array<string, string>>
     */
    private static function evidenceRecords(string $root, array $paths): array
    {
        sort($paths, SORT_STRING);
        return array_map(
            static function (string $path) use ($root): array {
                $hash = self::fileHash(self::rootFile($root, $path));
                return ['path' => $path, 'sha256' => $hash, 'retained' => 'evidence/sha256/' . $hash];
            },
            $paths,
        );
    }

    /** @param array<string, mixed> $inventory */
    public static function summary(array $inventory): string
    {
        $lines = [
            'Application packaged inventory',
            '',
            'Image: ' . $inventory['identity']['image_id'],
            'Target: ' . $inventory['identity']['target'],
            'Platform: ' . $inventory['identity']['platform'],
            'Observed revision: ' . $inventory['identity']['revision'],
            '',
            'Observed files: ' . count($inventory['files']),
            'Installed Debian packages: ' . count($inventory['debian']),
            'Installed Composer packages: ' . count($inventory['composer']),
            'Native build components: ' . count($inventory['native']),
            'Retained evidence paths: ' . count($inventory['evidence']),
            '',
            'Redistribution qualification remains open. Integrity verification does not approve licensing.',
            'A filesystem census cannot establish all dependencies embedded in compiled or generated bytes.',
            'JavaScript manifests and lockfiles establish copied source requirements, not installed dependencies.',
            '',
            'Review gaps: ' . count($inventory['review_gaps']),
        ];
        foreach ($inventory['review_gaps'] as $gap) {
            $lines[] = '- ' . $gap['path'] . ': ' . $gap['reason'] . (!empty($gap['component']) ? ' [' . $gap['component'] . ']' : '');
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    public static function generate(string $root, array $identity, string $output, string $revision): array
    {
        self::checkIdentity($identity, $revision);
        $absolute = str_starts_with($output, '/') ? $output : getcwd() . '/' . $output;
        $ancestor = $absolute;
        $suffix = [];
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            array_unshift($suffix, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        $ancestorReal = realpath($ancestor);
        if ($ancestorReal === false) {
            throw new InventoryError('Cannot resolve bundle output');
        }
        $absolute = self::normalize($ancestorReal . '/' . implode('/', $suffix));
        $rootReal = realpath($root) ?: throw new InventoryError('Missing export root');
        if ($absolute === $rootReal || str_starts_with($absolute, $rootReal . '/')) {
            throw new InventoryError('bundle output must be outside exported filesystem');
        }
        [$inventory, $paths] = self::observe($root, $identity);
        $inventory['evidence'] = self::evidenceRecords($root, $paths);
        self::directory($output);
        foreach ($inventory['evidence'] as $evidence) {
            $destination = $output . '/' . $evidence['retained'];
            self::directory(dirname($destination));
            if (!copy(self::rootFile($root, $evidence['path']), $destination)) {
                throw new InventoryError('Cannot retain evidence');
            }
        }
        file_put_contents($output . '/inventory.json', Json::canonical($inventory));
        file_put_contents($output . '/SUMMARY.txt', self::summary($inventory));
        $checksums = [
            'inventory.json' => self::fileHash($output . '/inventory.json'),
            'SUMMARY.txt' => self::fileHash($output . '/SUMMARY.txt'),
        ];
        foreach ($inventory['evidence'] as $evidence) {
            $checksums[$evidence['retained']] = $evidence['sha256'];
        }
        file_put_contents($output . '/checksums.json', Json::canonical($checksums));
        return $inventory;
    }

    public static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new InventoryError('Cannot create directory: ' . $path);
        }
    }

    /** @param array<string, mixed>|null $identity
     * @return array<string, mixed>
     */
    public static function verify(string $root, string $inventoryPath, string $revision, ?array $identity = null): array
    {
        $inventory = Json::read($inventoryPath);
        if (($inventory['schema'] ?? null) !== self::SCHEMA) {
            throw new InventoryError('unsupported inventory schema');
        }
        self::checkIdentity($inventory['identity'], $revision);
        if ($identity !== null && Json::canonical($identity) !== Json::canonical($inventory['identity'])) {
            throw new InventoryError('observed image identity differs from inventory');
        }
        [$actual, $paths] = self::observe($root, $inventory['identity']);
        $actual['evidence'] = self::evidenceRecords($root, $paths);
        if (Json::canonical($inventory) !== Json::canonical($actual)) {
            $old = array_column($inventory['files'] ?? [], null, 'path');
            $new = array_column($actual['files'], null, 'path');
            $changed = [...array_keys($old), ...array_keys($new)]
                |> array_unique(...)
                |> (static fn(array $paths): array => array_filter(
                    $paths,
                    static fn(string $path): bool => ($old[$path] ?? null) !== ($new[$path] ?? null),
                ))
                |> array_values(...);
            sort($changed, SORT_STRING);
            $details = ($changed ?: ['package metadata or review evidence'])
                |> (static fn(array $paths): array => array_slice($paths, 0, 20))
                |> (static fn(array $paths): string => implode(', ', $paths));
            throw new InventoryError('inventory differs from exported filesystem: ' . $details);
        }
        $bundle = dirname($inventoryPath);
        $expected = [
            'inventory.json' => self::fileHash($inventoryPath),
            'SUMMARY.txt' => hash('sha256', self::summary($inventory)),
        ];
        foreach ($inventory['evidence'] as $evidence) {
            if (self::fileHash(self::rootFile($bundle, $evidence['retained'])) !== $evidence['sha256']) {
                throw new InventoryError('retained evidence changed: ' . $evidence['path']);
            }
            $expected[$evidence['retained']] = $evidence['sha256'];
        }
        if (Json::canonical(Json::read($bundle . '/checksums.json')) !== Json::canonical($expected)) {
            throw new InventoryError('bundle checksums differ from inventory evidence');
        }
        if (self::fileHash(self::rootFile($bundle, 'SUMMARY.txt')) !== $expected['SUMMARY.txt']) {
            throw new InventoryError('readable summary changed');
        }
        return $inventory;
    }
}
