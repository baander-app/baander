<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Baander\Registry\Inventory;

final class InventoryError extends \RuntimeException {}

final class Inventory
{
    public const string BUNDLE_PATH = '/usr/share/registry-inventory';
    public const array EXECUTABLES = ['api' => ['baander-registry'], 'rqlite' => ['rqlited'], 'backup' => ['age', 'age-keygen', 'rclone']];

    public static function digest(string $data): string
    {
        return hash('sha256', $data);
    }

    public static function canonical(mixed $value): string
    {
        $previous = ini_set('serialize_precision', '-1');
        try {
            return self::encode($value, 0) . "\n";
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
    }

    private static function encode(mixed $value, int $depth): string
    {
        if (is_array($value) && array_is_list($value)) {
            if ($value === []) {
                return '[]';
            }
            $items = array_map(static fn($item) => str_repeat(' ', ($depth + 1) * 2) . self::encode($item, $depth + 1), $value);
            return "[\n" . implode(",\n", $items) . "\n" . str_repeat(' ', $depth * 2) . ']';
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $members = (array) $value;
            if ($members === []) {
                return '{}';
            }
            ksort($members, SORT_STRING);
            $items = [];
            foreach ($members as $key => $item) {
                $items[] = str_repeat(' ', ($depth + 1) * 2) . self::encode((string) $key, 0) . ': ' . self::encode($item, $depth + 1);
            }
            return "{\n" . implode(",\n", $items) . "\n" . str_repeat(' ', $depth * 2) . '}';
        }
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (is_float($value) && abs($value) >= 1.0e16 && stripos($encoded, 'e') === false) {
            $negative = str_starts_with($encoded, '-');
            $digits = explode('.', ltrim($encoded, '-'), 2)[0];
            $power = strlen($digits) - 1;
            $tail = rtrim(substr($digits, 1), '0');
            $encoded = ($negative ? '-' : '') . $digits[0] . ($tail === '' ? '' : '.' . $tail) . 'e+' . $power;
        }
        if (is_float($value) && stripos($encoded, 'e') !== false) {
            [$coefficient, $exponent] = explode('e', strtolower($encoded));
            $power = (int) $exponent;
            if ($power >= -4 && $power < 16) {
                $negative = str_starts_with($coefficient, '-');
                $digits = $coefficient
                    |> (static fn(string $number): string => ltrim($number, '-'))
                    |> (static fn(string $number): string => str_replace('.', '', $number));
                $decimal = 1 + $power;
                $encoded = ($negative ? '-' : '') . ($decimal <= 0
                    ? '0.' . str_repeat('0', -$decimal) . $digits
                    : (strlen($digits) <= $decimal
                        ? $digits . str_repeat('0', $decimal - strlen($digits)) . '.0'
                        : substr($digits, 0, $decimal) . '.' . substr($digits, $decimal)));
            } else {
                $coefficient = preg_replace('/\.0$/', '', $coefficient);
                $encoded = $coefficient . 'e' . ($power < 0 ? '-' : '+') . str_pad((string) abs($power), 2, '0', STR_PAD_LEFT);
            }
        }
        return $encoded;
    }

    public static function readJson(string $path): mixed
    {
        return $path
            |> self::read(...)
            |> (static fn(string $json): mixed => json_decode($json, false, 512, JSON_THROW_ON_ERROR))
            |> self::arrays(...);
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            if ((array) $value === []) {
                return $value;
            }
            $members = array_map(self::arrays(...), (array) $value);
            return array_is_list($members) ? (object) $members : $members;
        }
        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }

    public static function read(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new InventoryError('cannot read: ' . $path);
        }
        return $data;
    }

    public static function write(string $path, string $data): void
    {
        self::directory(dirname($path));
        if (file_put_contents($path, $data) === false) {
            throw new InventoryError('cannot write: ' . $path);
        }
    }

    public static function directory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new InventoryError('cannot create directory: ' . $path);
        }
    }

    public static function rootFile(string $root, string $name): string
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
                    throw new InventoryError('copyright symlink loop: ' . $name);
                }
                $target = readlink($candidate);
                if ($target === false) {
                    throw new InventoryError('cannot read symlink: ' . $candidate);
                }
                if (str_starts_with($target, '/')) {
                    $parts = [];
                }
                $pending = [...explode('/', ltrim($target, '/')), ...$pending];
            } else {
                $parts[] = $part;
            }
        }
        $result = $root . '/' . implode('/', $parts);
        if (!is_file($result)) {
            throw new InventoryError('missing retained file: ' . $name);
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function goBuildinfo(string $data, string $name): array
    {
        $offset = strpos($data, "\xff Go buildinf:");
        if ($offset === false || strlen($data) < $offset + 32 || !(ord($data[$offset + 15]) & 2)) {
            throw new InventoryError($name . ': missing usable Go build information');
        }
        $position = $offset + 32;
        $readString = static function () use ($data, $name, &$position): string {
            $length = $shift = 0;
            do {
                if ($position >= strlen($data) || $shift > 63) {
                    throw new InventoryError($name . ': invalid Go build information length');
                }
                $byte = ord($data[$position++]);
                if ($shift === 63 && ($byte & 127) !== 0) {
                    throw new InventoryError($name . ': invalid Go build information length');
                }
                $length |= ($byte & 127) << $shift;
                $shift += 7;
            } while ($byte >= 128);
            if ($length < 0 || $length > strlen($data) - $position) {
                throw new InventoryError($name . ': truncated Go build information');
            }
            $result = substr($data, $position, $length);
            $position += $length;
            return $result;
        };
        $version = $readString();
        $raw = $readString();
        if (!str_starts_with($version, 'go1.') || strlen($raw) < 32 || !preg_match('//u', $version . substr($raw, 16, -16))) {
            throw new InventoryError($name . ': invalid Go build information');
        }
        $modules = $settings = [];
        $package = null;
        foreach (explode("\n", substr($raw, 16, -16)) as $line) {
            $fields = explode("\t", $line);
            if ($fields[0] === 'path' && count($fields) === 2) {
                $package = $fields[1];
            } elseif (in_array($fields[0], ['mod', 'dep', '=>'], true)) {
                if (count($fields) < 3) {
                    throw new InventoryError($name . ': incomplete Go module identity: ' . $line);
                }
                $entry = ['path' => $fields[1], 'version' => $fields[2], 'sum' => $fields[3] ?? ''];
                if ($fields[0] === '=>') {
                    $index = array_key_last($modules);
                    if ($index === null || isset($modules[$index]['replacement'])) {
                        throw new InventoryError($name . ': unmatched Go module replacement');
                    }
                    $modules[$index]['replacement'] = $entry;
                } else {
                    $modules[] = $entry + ['main' => $fields[0] === 'mod'];
                }
            } elseif ($fields[0] === 'build' && count($fields) === 2 && str_contains($fields[1], '=')) {
                [$key, $value] = explode('=', $fields[1], 2);
                $settings[$key] = $value;
            }
        }
        if (!$package || !$modules) {
            throw new InventoryError($name . ': incomplete Go build information');
        }
        usort($modules, static fn($a, $b) => strcmp($a['path'], $b['path']));
        return [
            'go_version' => $version,
            'package' => $package,
            'modules' => $modules,
            'settings' => $settings === [] ? new \stdClass() : $settings,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function debianStatus(string $text): array
    {
        $records = [];
        foreach (explode("\n\n", $text) as $paragraph) {
            $fields = [];
            $key = null;
            foreach (explode("\n", $paragraph) as $line) {
                if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                    if ($key !== null) {
                        $fields[$key] .= "\n" . $line;
                    }
                } elseif (str_contains($line, ': ')) {
                    [$key, $value] = explode(': ', $line, 2);
                    $fields[$key] = $value;
                }
            }
            if (($fields['Status'] ?? '') === 'install ok installed') {
                foreach (['Package', 'Version', 'Architecture'] as $required) {
                    if (!isset($fields[$required])) {
                        throw new InventoryError('incomplete Debian package');
                    }
                }
                $records[] = $fields;
            }
        }
        if ($records === []) {
            throw new InventoryError('no installed Debian packages observed');
        }
        usort($records, static fn($a, $b) => [$a['Package'], $a['Architecture']] <=> [$b['Package'], $b['Architecture']]);
        return $records;
    }

    private static function unresolvedLicense(mixed $value): bool
    {
        return !is_string($value) || in_array(strtolower(trim($value)), ['', 'unknown', 'noassertion'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function resolutionData(string $directory): array
    {
        $paths = [...(glob($directory . '/*/components.json') ?: []), ...(glob($directory . '/*/embedded.json') ?: [])];
        sort($paths, SORT_STRING);
        $components = [];
        foreach ($paths as $path) {
            if (basename(dirname($path)) === 'debian') {
                continue;
            }
            $value = self::readJson($path);
            $components = [...$components, ...(array_is_list($value) ? $value : $value['components'])];
        }
        $ids = array_column($components, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new InventoryError('duplicate reviewed component identity');
        }
        foreach ($components as $component) {
            $missing = array_diff($component['incorporated_components'] ?? [], $ids);
            if ($missing) {
                sort($missing);
                throw new InventoryError('missing incorporated component evidence for ' . $component['id'] . ': ' . implode(', ', $missing));
            }
            if (self::unresolvedLicense($component['license'] ?? null)) {
                throw new InventoryError('unresolved license: ' . $component['id']);
            }
            if (empty($component['notices'])) {
                throw new InventoryError('missing notices: ' . $component['id']);
            }
            foreach ($component['notices'] as $notice) {
                $hash = self::rootFile($directory, $notice['path']) |> self::read(...) |> self::digest(...);
                if ($hash !== $notice['sha256']) {
                    throw new InventoryError('reviewed notice digest changed: ' . $component['id'] . ': ' . $notice['path']);
                }
            }
        }
        return $components;
    }

    /**
     * @param list<array<string, mixed>> $debianResolutions
     * @return array{list<array<string, mixed>>, list<string>}
     */
    public static function packageObservations(string $root, array $debianResolutions): array
    {
        $packages = $noticePaths = [];
        $status = self::rootFile($root, '/var/lib/dpkg/status')
            |> self::read(...)
            |> self::debianStatus(...);
        foreach ($status as $fields) {
            $name = $fields['Package'];
            $copyright = '/usr/share/doc/' . $name . '/copyright';
            $text = self::read(self::rootFile($root, $copyright));
            $sha = self::digest($text);
            preg_match_all('/^License:[ \t]*(\S[^\n]*)/m', $text, $matches);
            $declarations = $matches[1] |> array_unique(...) |> array_values(...);
            sort($declarations, SORT_STRING);
            $resolution = null;
            foreach ($debianResolutions as $candidate) {
                if ($candidate['copyright_sha256'] === $sha) {
                    $resolution = $candidate;
                    break;
                }
            }
            if ($resolution !== null) {
                $license = $resolution['license'];
            } elseif ($declarations && str_contains($text, 'Format:')) {
                $license = 'LicenseRef-Debian-' . preg_replace('/[^A-Za-z0-9.-]/', '-', $name) . '-' . substr($sha, 0, 12);
            } else {
                throw new InventoryError($name . ': unresolved Debian copyright license ' . $sha);
            }
            if (self::unresolvedLicense($license) || array_any($declarations, self::unresolvedLicense(...))) {
                throw new InventoryError($name . ': unresolved Debian copyright license ' . $sha);
            }
            $notices = [$copyright];
            preg_match_all('~/usr/share/common-licenses/([A-Za-z0-9.+_-]+)~', $text, $matches);
            foreach ($matches[1] as $relative) {
                $relative = rtrim($relative, '.');
                $relative = $resolution['common_license_aliases'][$relative] ?? $relative;
                $path = '/usr/share/common-licenses/' . $relative;
                self::rootFile($root, $path);
                $notices[] = $path;
            }
            $notices = $notices |> array_unique(...) |> array_values(...);
            sort($notices, SORT_STRING);
            $noticePaths = [...$noticePaths, ...$notices];
            $packages[] = [
                'name' => $name,
                'version' => $fields['Version'],
                'architecture' => $fields['Architecture'],
                'source' => $fields['Source'] ?? $name,
                'license' => $license,
                'copyright_sha256' => $sha,
                'notices' => $notices,
                'license_declarations' => $declarations,
                'license_scope' => 'Debian source-package copyright declarations; not per-file legal conclusion',
            ];
        }
        $noticePaths = $noticePaths |> array_unique(...) |> array_values(...);
        sort($noticePaths, SORT_STRING);
        return [$packages, $noticePaths];
    }

    /**
     * @param array<string, mixed> $info
     * @param list<array<string, mixed>> $components
     * @return list<string>
     */
    public static function componentIds(array $info, array $components): array
    {
        $selected = ['go-toolchain:' . $info['go_version']];
        foreach ($info['modules'] as $module) {
            $effective = $module['replacement'] ?? $module;
            $selected[] = 'go:' . $effective['path'] . '@' . $effective['version'];
        }
        foreach ($components as $component) {
            if (in_array($component['id'], $selected, true) && !empty($component['unresolved'])) {
                throw new InventoryError('unresolved source evidence: ' . $component['id'] . ': ' . $component['unresolved']);
            }
        }
        $missing = array_diff($selected, array_column($components, 'id'));
        if ($missing) {
            sort($missing);
            throw new InventoryError('unresolved executable components: ' . implode(', ', $missing));
        }
        foreach ($components as $component) {
            if (in_array($component['parent'] ?? null, $selected, true)) {
                $selected[] = $component['id'];
            }
            if (in_array($component['id'], $selected, true)) {
                $selected = [...$selected, ...($component['incorporated_components'] ?? [])];
            }
        }
        $selected = $selected |> array_unique(...) |> array_values(...);
        sort($selected, SORT_STRING);
        return $selected;
    }

    /**
     * @return list<string>
     */
    public static function words(string $command): array
    {
        $words = [];
        $word = '';
        $quote = null;
        $started = false;
        for ($i = 0, $length = strlen($command); $i < $length; ++$i) {
            $char = $command[$i];
            if ($char === "'" && $quote !== '"') {
                $quote = $quote === "'" ? null : "'";
                $started = true;
            } elseif ($char === '"' && $quote !== "'") {
                $quote = $quote === '"' ? null : '"';
                $started = true;
            } elseif ($char === '\\' && $quote !== "'") {
                if (++$i >= $length) {
                    throw new InventoryError('unfinished command escape');
                }
                $next = $command[$i];
                if ($quote === '"' && !in_array($next, ['$', '`', '"', '\\', "\n"], true)) {
                    $word .= '\\';
                }
                if ($next !== "\n") {
                    $word .= $next;
                }
                $started = true;
            } elseif ($quote === null && str_contains(" \t\r\n", $char)) {
                if ($started) {
                    $words[] = $word;
                    $word = '';
                    $started = false;
                }
            } else {
                $word .= $char;
                $started = true;
            }
        }
        if ($quote !== null) {
            throw new InventoryError('unfinished command quote');
        }
        if ($started) {
            $words[] = $word;
        }
        return $words;
    }

    /**
     * @return list<string>
     */
    public static function apiStaticArchives(string $commands): array
    {
        $paths = $commands
            |> self::words(...)
            |> (static fn(array $tokens): array => array_filter(
                $tokens,
                static fn(string $token): bool => str_ends_with($token, '.a'),
            ))
            |> array_unique(...)
            |> array_values(...);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, string> $sourceFiles
     */
    public static function validateApiSources(array $evidence, array $sourceFiles): void
    {
        $observed = [];
        foreach ($evidence['inputs'] as $item) {
            foreach (['_deps/boost_headers-src/' => 'boost/', '_deps/json-src/' => 'json/'] as $marker => $prefix) {
                if (str_contains($item['path'], $marker)) {
                    $source = $prefix . explode($marker, $item['path'], 2)[1];
                    if (($sourceFiles[$source] ?? null) !== $item['sha256']) {
                        throw new InventoryError('API compiled source differs from pinned archive: ' . $source);
                    }
                    $observed[$prefix] = true;
                }
            }
        }
        if (count($observed) !== 2) {
            throw new InventoryError('API compiler evidence lacks pinned Boost/json source observations');
        }
        $commands = self::apiStaticArchives($evidence['link_commands'] ?? '');
        $recorded = array_column($evidence['static_libraries'] ?? [], 'path');
        $recorded = $recorded |> array_unique(...) |> array_values(...);
        sort($recorded, SORT_STRING);
        $unknown = array_diff(
            [...$commands, ...$recorded],
            [
                '/opt/openssl/lib/libssl.a',
                '/opt/openssl/lib/libcrypto.a',
                'libregistry_core.a',
                'libregistry_transport.a',
                'libregistry_boost_url.a',
            ],
        );
        if ($unknown) {
            sort($unknown);
            throw new InventoryError('unreviewed API static archive: ' . implode(', ', array_unique($unknown)));
        }
        if ($commands !== $recorded) {
            throw new InventoryError('API static archive observations differ from linker commands');
        }
        if (
            array_diff(['libssl.a', 'libcrypto.a'], array_map(basename(...), $recorded))
            || empty($evidence['linker_map'])
            || !str_contains($evidence['link_commands'] ?? '', 'libssl.a')
            || !str_contains($evidence['link_commands'] ?? '', 'libcrypto.a')
        ) {
            throw new InventoryError('API evidence lacks static OpenSSL linker inputs/map');
        }
    }

    /**
     * @param list<array<string, mixed>> $modules
     * @return list<array<string, mixed>>
     */
    private static function moduleIdentities(array $modules): array
    {
        $result = [];
        foreach ($modules as $module) {
            $item = array_intersect_key($module + ['path' => '', 'version' => '', 'sum' => ''], array_flip(['path', 'version', 'sum']));
            if (isset($module['replacement'])) {
                $item['replacement'] = array_intersect_key($module['replacement'] + ['path' => '', 'version' => '', 'sum' => ''], array_flip(['path', 'version', 'sum']));
            }
            $result[] = $item;
        }
        usort($result, static fn($a, $b) => strcmp($a['path'], $b['path']));
        return $result;
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $expected
     */
    public static function validateSourceBuild(string $name, array $info, array $expected): void
    {
        $settings = (array) $info['settings'];
        $reviewed = (array) $expected['settings'];
        if (($settings['vcs.revision'] ?? null) !== $expected['expected_vcs_revision'] || ($settings['vcs.modified'] ?? null) !== 'false' || $info['go_version'] !== $expected['go_version']) {
            throw new InventoryError($name . ': source-built executable VCS/toolchain differs from reviewed source');
        }
        if ($info['package'] !== $expected['package'] || array_any(
            ['CGO_ENABLED', 'GOOS', 'GOARCH', 'GOAMD64', '-tags', '-buildmode', '-ldflags'],
            static fn($key) => ($settings[$key] ?? '') !== ($reviewed[$key] ?? ''),
        )) {
            throw new InventoryError($name . ': source-built package/runtime build settings differ from reviewed source');
        }
        if (self::canonical(self::moduleIdentities($info['modules'])) !== self::canonical(self::moduleIdentities($expected['modules']))) {
            throw new InventoryError($name . ': source-built module graph differs from reviewed source');
        }
    }

    /**
     * @param list<array<string, mixed>> $components
     * @param list<array<string, mixed>> $debian
     * @param list<array<string, mixed>> $reviewed
     * @param array<string, string> $sourceFiles
     * @return array{array<string, mixed>, list<string>}
     */
    public static function observe(string $root, string $kind, array $components, array $debian, array $reviewed, array $sourceFiles = []): array
    {
        if (!isset(self::EXECUTABLES[$kind])) {
            throw new InventoryError('unsupported image kind: ' . $kind);
        }
        $allowed = [
            ...self::EXECUTABLES[$kind],
            ...(['api' => [], 'rqlite' => ['rqlite-entrypoint'], 'backup' => ['registry-backup']][$kind]),
        ];
        $unknown = [];
        foreach (scandir($root . '/usr/local/bin') ?: [] as $name) {
            if (is_file($root . '/usr/local/bin/' . $name) && !in_array($name, $allowed, true)) {
                $unknown[] = $name;
            }
        }
        if ($unknown) {
            sort($unknown);
            throw new InventoryError('unreviewed packaged executable: ' . implode(', ', $unknown));
        }
        $executables = $selected = [];
        foreach (self::EXECUTABLES[$kind] as $name) {
            $path = '/usr/local/bin/' . $name;
            $data = self::read(self::rootFile($root, $path));
            $record = ['path' => $path, 'sha256' => self::digest($data)];
            if ($kind !== 'api') {
                $info = self::goBuildinfo($data, $name);
                $record += $info;
                $expected = array_find($reviewed, static fn($item) => $item['path'] === $path);
                if ($expected === null) {
                    throw new InventoryError($name . ': missing reviewed executable observation');
                }
                if (!empty($expected['source_built'])) {
                    self::validateSourceBuild($name, $info, $expected);
                } elseif ($expected['sha256'] !== $record['sha256']) {
                    throw new InventoryError($name . ': executable differs from reviewed artifact');
                }
                $record['components'] = self::componentIds($info, $components);
            } else {
                $evidence = self::readJson(self::rootFile($root, self::BUNDLE_PATH . '/api-build.json'));
                if ($evidence['sha256'] !== $record['sha256']) {
                    throw new InventoryError($name . ': API compiler/link evidence differs from binary');
                }
                self::validateApiSources($evidence, $sourceFiles);
                $record['build_evidence'] = $evidence;
                $record['components'] = $components
                    |> (static fn(array $items): array => array_filter(
                        $items,
                        static fn(array $item): bool => ($item['family'] ?? null) === 'api' && ($item['scope'] ?? null) === 'runtime',
                    ))
                    |> (static fn(array $items): array => array_column($items, 'id'));
                sort($record['components'], SORT_STRING);
                if (!$record['components']) {
                    throw new InventoryError('API source resolutions missing');
                }
            }
            $selected = [...$selected, ...$record['components']];
            $executables[] = $record;
        }
        [$packages, $notices] = self::packageObservations($root, $debian);
        $selectedComponents = $components
            |> (static fn(array $items): array => array_filter(
                $items,
                static fn(array $item): bool => in_array($item['id'], $selected, true),
            ))
            |> array_values(...);
        usort($selectedComponents, static fn($a, $b) => strcmp($a['id'], $b['id']));
        return [
            [
                'schema' => 'baander-packaged-inventory/v1',
                'kind' => $kind,
                'executables' => $executables,
                'packages' => $packages,
                'components' => $selectedComponents,
            ],
            $notices,
        ];
    }

    /**
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private static function loadInputs(string $directory): array
    {
        $components = self::resolutionData($directory);
        $debian = is_file($directory . '/debian/components.json') ? self::readJson($directory . '/debian/components.json') : [];
        if (!array_is_list($debian)) {
            $debian = $debian['components'];
        }
        $executables = is_file($directory . '/go/executables.json') ? self::readJson($directory . '/go/executables.json') : [];
        if (!array_is_list($executables)) {
            $executables = $executables['executables'] ?? array_values($executables);
        }
        return [$components, $debian, $executables];
    }

    public static function copyTree(string $source, string $destination): void
    {
        self::directory($destination);
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $name) {
            $from = $source . '/' . $name;
            $to = $destination . '/' . $name;
            if (is_dir($from) && !is_link($from)) {
                self::copyTree($from, $to);
            } else {
                self::write($to, self::read(is_link($from) ? self::rootFile($source, $name) : $from));
            }
        }
    }

    public static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
                self::removeTree($path . '/' . $name);
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function generate(string $root, string $kind, string $resolutions, string $output): array
    {
        [$components, $debian, $reviewed] = self::loadInputs($resolutions);
        $source = is_file($resolutions . '/native/source-files.json') ? self::readJson($resolutions . '/native/source-files.json') : [];
        [$inventory, $notices] = self::observe($root, $kind, $components, $debian, $reviewed, (array) $source);
        self::copyTree($resolutions, $output . '/resolutions');
        $inventory['debian_notices'] = [];
        foreach ($notices as $path) {
            $data = self::read(self::rootFile($root, $path));
            self::write($output . '/debian-notices/' . ltrim($path, '/'), $data);
            $inventory['debian_notices'][] = [
                'path' => $path,
                'sha256' => self::digest($data),
                'retained' => self::BUNDLE_PATH . '/debian-notices/' . ltrim($path, '/'),
            ];
        }
        self::write($output . '/inventory.json', self::canonical($inventory));
        return $inventory;
    }

    /**
     * @return array<string, string>
     */
    private static function treeManifest(string $directory): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($directory) + 1);
                $result[$relative] = self::rootFile($directory, $relative)
                    |> self::read(...)
                    |> self::digest(...);
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function verify(string $root, string $inventoryPath, ?string $trustedResolutions = null): array
    {
        $inventory = self::readJson($inventoryPath);
        $bundle = dirname($inventoryPath);
        if ($trustedResolutions !== null && self::treeManifest($bundle . '/resolutions') !== self::treeManifest($trustedResolutions)) {
            throw new InventoryError('retained resolutions differ from independently reviewed source evidence');
        }
        [$components, $debian, $reviewed] = self::loadInputs($bundle . '/resolutions');
        $source = is_file($bundle . '/resolutions/native/source-files.json') ? self::readJson($bundle . '/resolutions/native/source-files.json') : [];
        [$actual, $notices] = self::observe($root, $inventory['kind'], $components, $debian, $reviewed, (array) $source);
        $actual['debian_notices'] = array_map(
            static fn($path) => [
                'path' => $path,
                'sha256' => self::rootFile($root, $path) |> self::read(...) |> self::digest(...),
                'retained' => self::BUNDLE_PATH . '/debian-notices/' . ltrim($path, '/'),
            ],
            $notices,
        );
        if (self::canonical(array_intersect_key($inventory, $actual)) !== self::canonical($actual)) {
            throw new InventoryError('packaged inventory differs from final filesystem: executable, component or Debian package observation');
        }
        foreach ($inventory['debian_notices'] as $notice) {
            $current = self::read(self::rootFile($root, $notice['path']));
            $retained = self::read(self::rootFile($root, $notice['retained']));
            if (self::digest($current) !== $notice['sha256'] || $current !== $retained) {
                throw new InventoryError('Debian retained notice changed: ' . $notice['path']);
            }
        }
        return $inventory;
    }

    /**
     * @param non-empty-list<string> $argv
     */
    public static function run(array $argv): string
    {
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new InventoryError('cannot start ' . $argv[0]);
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new InventoryError('command failed: ' . implode(' ', $argv));
        }
        return $output;
    }

    public static function captureApi(string $build, string $output): void
    {
        $commands = self::run(['ninja', '-C', $build, '-t', 'commands', 'baander-registry']);
        $deps = self::run(['ninja', '-C', $build, '-t', 'deps']);
        $compile = self::readJson($build . '/compile_commands.json');
        $paths = [];
        foreach (explode("\n", $deps) as $line) {
            if (str_starts_with($line, '    ')) {
                $path = trim($line);
                $paths[] = str_starts_with($path, '/') ? $path : $build . '/' . $path;
            }
        }
        foreach ($compile as $command) {
            $paths[] = $command['file'];
        }
        $paths = $paths |> array_unique(...) |> array_values(...);
        sort($paths, SORT_STRING);
        if (!$paths || !array_any($paths, static fn($path) => str_contains($path, '_deps/boost_headers-src/boost/')) || !array_any($paths, static fn($path) => str_contains($path, '_deps/json-src/'))) {
            throw new InventoryError('API compiler evidence lacks observed Boost/json headers');
        }
        if (!str_contains($commands, 'libssl.a') || !str_contains($commands, 'libcrypto.a')) {
            throw new InventoryError('API linker evidence lacks static OpenSSL archives');
        }
        $libraries = [];
        foreach (self::apiStaticArchives($commands) as $path) {
            $libraries[] = [
                'path' => $path,
                'sha256' => self::digest(self::read(str_starts_with($path, '/') ? $path : $build . '/' . $path)),
            ];
        }
        $evidence = [
            'sha256' => ($build . '/baander-registry') |> self::read(...) |> self::digest(...),
            'static_libraries' => $libraries,
            'compile_commands' => $compile,
            'link_commands' => $commands,
            'inputs' => array_map(
                static fn(string $path): array => ['path' => $path, 'sha256' => $path |> self::read(...) |> self::digest(...)],
                $paths,
            ),
            'linker_map' => self::read($build . '/baander-registry.map'),
        ];
        self::write($output, self::canonical($evidence));
    }

    /**
     * @param list<string> $argv
     * @return array<string, string>
     */
    public static function options(array $argv): array
    {
        $options = [];
        for ($i = 0; $i < count($argv); $i += 2) {
            if (!str_starts_with($argv[$i], '--') || !isset($argv[$i + 1])) {
                throw new InventoryError('expected --option value');
            }
            $options[substr($argv[$i], 2)] = $argv[$i + 1];
        }
        return $options;
    }
}
