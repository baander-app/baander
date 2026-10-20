#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Retain native build inputs before cleanup, then bind them to final-image outputs. */
function options(array $arguments): array
{
    $result = [];
    while ($arguments !== []) {
        $key = array_shift($arguments);
        if (!str_starts_with($key, '--') || $arguments === []) {
            throw new RuntimeException('Expected --option value pairs.');
        }
        $result[substr($key, 2)][] = array_shift($arguments);
    }
    return $result;
}

function required(array $options, string $key): string
{
    return $options[$key][0] ?? throw new RuntimeException("Missing --{$key}.");
}

function writeFile(string $path, string $content): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
        throw new RuntimeException("Cannot create directory for {$path}.");
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException("Cannot write {$path}.");
    }
}

function readFileChecked(string $path): string
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }
    return $content;
}

function writeManifest(string $output, array $components): void
{
    writeFile($output . '/components.json', json_encode([
        'schema' => 'baander-native-build-evidence/v1',
        'components' => $components,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function appendComponent(string $output, array $component): void
{
    $components = [];
    if (is_file($output . '/components.json')) {
        $manifest = json_decode(readFileChecked($output . '/components.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== 'baander-native-build-evidence/v1') {
            throw new RuntimeException('Unsupported existing evidence manifest.');
        }
        $components = $manifest['components'];
        foreach ($components as $existing) {
            if ($existing['id'] === $component['id']) {
                throw new RuntimeException('Duplicate component: ' . $component['id']);
            }
        }
    }
    $components[] = $component;
    writeManifest($output, $components);
}

function safeRelative(string $relative): string
{
    if ($relative === '' || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
        throw new RuntimeException("Unsafe evidence path: {$relative}");
    }
    return $relative;
}

function noticeName(string $name): bool
{
    return preg_match('/^(?:licen[cs]e|copying|copyright|notice)(?:$|[._-])/i', $name) === 1;
}

function retain(string $output, string $id, string $relative, string $content): string
{
    $relative = safeRelative($relative);
    writeFile($output . '/components/' . $id . '/' . $relative, $content);
    return '/usr/share/baander-build-evidence/components/' . $id . '/' . $relative;
}

function component(array $options): array
{
    $id = required($options, 'id');
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $id) !== 1) {
        throw new RuntimeException('Component id must contain only letters, digits, dots, underscores and hyphens.');
    }
    $metadata = ['artifact_patterns' => $options['artifact'] ?? [], 'retained_metadata' => []];
    foreach ($options['metadata'] ?? [] as $item) {
        $pair = explode('=', $item, 2);
        if (count($pair) !== 2 || $pair[0] === '') {
            throw new RuntimeException('Expected --metadata key=value.');
        }
        $metadata[$pair[0]] = $pair[1];
    }
    return [
        'id' => $id,
        'name' => required($options, 'name'),
        'version' => $options['version'][0] ?? null,
        'source' => [
            'url' => $options['source-url'][0] ?? null,
            'revision' => $options['source-revision'][0] ?? null,
            'sha256' => isset($options['source-archive']) ? hash_file('sha256', required($options, 'source-archive')) : null,
        ],
        'artifacts' => [],
        'notices' => [],
        'metadata' => $metadata,
        'embedded_dependencies' => 'unreviewed',
    ];
}

function collect(array $options): void
{
    $output = required($options, 'output');
    $component = component($options);
    $source = realpath(required($options, 'source-dir'));
    if ($source === false || !is_dir($source)) {
        throw new RuntimeException('Source directory does not exist.');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    $tree = [];
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if ($file->isLink()) {
            $resolved = $file->getRealPath();
            if ($resolved === false || !str_starts_with($resolved, $source . '/')) {
                continue;
            }
        }
        $relative = substr($file->getPathname(), strlen($source) + 1);
        // Git internals are neither distributed source nor build configuration.
        if (str_starts_with($relative, '.git/')) {
            continue;
        }
        $tree[$relative] = hash_file('sha256', $file->getPathname());
        if (noticeName($file->getFilename())) {
            $component['notices'][] = retain($output, $component['id'], 'source/' . $relative, readFileChecked($file->getPathname()));
        }
        if (in_array($relative, ['config.nice', 'package.xml', 'package2.xml'], true)) {
            $component['metadata']['retained_metadata'][] = retain($output, $component['id'], 'source/' . $relative, readFileChecked($file->getPathname()));
        }
    }
    ksort($tree);
    $component['metadata']['source_tree_sha256'] = hash('sha256', json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (isset($options['package-xml'])) {
        $xml = readFileChecked(required($options, 'package-xml'));
        $component['metadata']['retained_metadata'][] = retain($output, $component['id'], 'package.xml', $xml);
        if (preg_match('/<version>\s*<release>([^<]+)<\/release>/s', $xml, $match) === 1) {
            $component['version'] = trim($match[1]);
        } elseif ($component['version'] === null) {
            $component['metadata']['version_gap'] = 'No release version found in retained package.xml.';
        }
    }
    sort($component['notices']);
    appendComponent($output, $component);
}

function collectPhar(array $options): void
{
    $output = required($options, 'output');
    $component = component($options);
    $archive = realpath(required($options, 'archive'));
    if ($archive === false || !is_file($archive)) {
        throw new RuntimeException('PHAR archive does not exist.');
    }
    // Load only the archive index. Never include the archive or its PHP metadata.
    $alias = 'baander-evidence-' . $component['id'] . '.phar';
    Phar::loadPhar($archive, $alias);
    $prefix = 'phar://' . $alias . '/';
    $versionSource = match ($component['id']) {
        'composer' => ['src/Composer/Composer.php', 'public', 'VERSION'],
        'pie' => ['src/Util/PieVersion.php', 'private', 'PIE_VERSION'],
        default => null,
    };
    $component['metadata']['own_notices'] = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($prefix, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = safeRelative(substr($file->getPathname(), strlen($prefix)));
        if (noticeName($file->getFilename())) {
            $notice = retain($output, $component['id'], 'archive/' . $relative, readFileChecked($file->getPathname()));
            $component['notices'][] = $notice;
            if (!str_contains($relative, '/')) {
                $component['metadata']['own_notices'][] = $notice;
            }
        }
        if ($versionSource !== null && $relative === $versionSource[0]) {
            $text = readFileChecked($file->getPathname());
            $versionEvidence = retain($output, $component['id'], 'archive/' . $relative, $text);
            $component['metadata']['retained_metadata'][] = $versionEvidence;
            $component['metadata']['version_source'] = $versionEvidence;
            $pattern = '/^\\s*' . $versionSource[1] . '\\s+const\\s+' . $versionSource[2]
                . '\\s*=\\s*([\'\"])([0-9]+\\.[0-9]+\\.[0-9]+)\\1\\s*;/m';
            if (preg_match($pattern, $text, $match) === 1) {
                $component['version'] = $match[2];
            }
        }
        if (in_array($file->getFilename(), ['composer.json', 'installed.json', 'installed.php'], true)) {
            $component['metadata']['retained_metadata'][] = retain($output, $component['id'], 'archive/' . $relative, readFileChecked($file->getPathname()));
        }
    }
    $component['source']['sha256'] = hash_file('sha256', $archive);
    $component['metadata']['archive_path'] = required($options, 'archive');
    $component['metadata']['own_notices_gap'] = $component['metadata']['own_notices'] === []
        ? 'No top-level notice for the tool itself found in the archive; embedded dependency notices do not establish tool notice coverage.'
        : null;
    $component['metadata']['version_gap'] = $component['version'] === null ? 'Archive version not independently established; inspect retained package metadata.' : null;
    sort($component['notices']);
    appendComponent($output, $component);
}

function finalize(array $options): void
{
    $output = required($options, 'output');
    $components = [];
    foreach ($options['input'] ?? [] as $input) {
        $manifest = json_decode(readFileChecked($input . '/components.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['schema'] ?? null) !== 'baander-native-build-evidence/v1') {
            throw new RuntimeException("Unsupported manifest in {$input}.");
        }
        foreach ($manifest['components'] as $component) {
            $id = $component['id'];
            if (isset($components[$id])) {
                throw new RuntimeException("Duplicate component: {$id}");
            }
            foreach (array_merge($component['notices'], $component['metadata']['retained_metadata']) as $path) {
                $prefix = '/usr/share/baander-build-evidence/';
                if (!str_starts_with($path, $prefix)) {
                    throw new RuntimeException("Invalid evidence reference: {$path}");
                }
                $relative = safeRelative(substr($path, strlen($prefix)));
                writeFile($output . '/' . $relative, readFileChecked($input . '/' . $relative));
            }
            $artifacts = [];
            $missing = [];
            foreach ($component['metadata']['artifact_patterns'] as $pattern) {
                if (!str_starts_with($pattern, '/')) {
                    throw new RuntimeException("Artifact pattern must be absolute: {$pattern}");
                }
                $matches = glob($pattern, GLOB_BRACE);
                if ($matches === false) {
                    throw new RuntimeException("Cannot expand artifact pattern: {$pattern}");
                }
                $found = false;
                foreach ($matches as $path) {
                    if (is_file($path)) {
                        $artifacts[$path] = ['path' => $path, 'sha256' => hash_file('sha256', $path)];
                        $found = true;
                    }
                }
                if (!$found) {
                    if (strpbrk($pattern, '*?[{') === false) {
                        throw new RuntimeException("Expected final artifact is missing: {$pattern}");
                    }
                    $missing[] = $pattern;
                }
            }
            ksort($artifacts);
            $component['artifacts'] = array_values($artifacts);
            $component['metadata']['unmatched_artifact_patterns'] = $missing;
            $component['metadata']['notices_gap'] = $component['notices'] === [] ? 'No upstream notices found in retained source/archive.' : null;
            $components[$id] = $component;
        }
    }
    if ($components === []) {
        throw new RuntimeException('No components supplied; specify --input directories.');
    }
    ksort($components);
    writeManifest($output, array_values($components));
}

try {
    $command = $argv[1] ?? 'help';
    if ($command === 'help' || $command === '--help') {
        echo "Usage: retain-build-evidence.php collect|phar|finalize --output DIR [options]\n";
        echo "collect: --id ID --name NAME --source-dir DIR [--version VERSION] [--package-xml FILE]\n";
        echo "phar: --id ID --name NAME --archive FILE [--version VERSION]\n";
        echo "Both: [--source-url URL] [--source-revision REV] [--source-archive FILE] [--artifact GLOB] [--metadata KEY=VALUE]\n";
        echo "finalize: --input DIR (repeatable); hashes artifacts in the final filesystem.\n";
        exit(0);
    }
    $options = options(array_slice($argv, 2));
    match ($command) {
        'collect' => collect($options),
        'phar' => collectPhar($options),
        'finalize' => finalize($options),
        default => throw new RuntimeException("Unknown command: {$command}"),
    };
} catch (Throwable $error) {
    fwrite(STDERR, 'Build evidence: ' . $error->getMessage() . "\n");
    exit(1);
}
