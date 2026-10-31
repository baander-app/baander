<?php

declare(strict_types=1);

namespace Baander\Tooling\Dependencies;

use Baander\Tooling\Filesystem\PrivateDirectory;

final class ForkEvidence
{
    /**
     * @param array<string,mixed> $fork
     * @param array<string,mixed> $comparison
     */
    public static function collect(string $root, string $package, array $fork, array $comparison, Report $report): void
    {
        if (!is_string($comparison['reference'] ?? null) || $comparison['reference'] === '' || !is_string($comparison['tag'] ?? null) || $comparison['tag'] === '') {
            throw new \RuntimeException('Fork comparison needs nonempty reviewed reference and tag strings.');
        }
        $path = $fork['path'] ?? null;
        if (!is_string($path)) {
            throw new \RuntimeException('Fork source inventory needs a repository-relative package path.');
        }
        self::relativePath($path);
        $files = $comparison['files'] ?? null;
        if (!is_array($files) || !array_is_list($files) || $files === []) {
            throw new \RuntimeException('Fork comparison requires a nonempty source file inventory.');
        }
        $expected = [];
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)) {
                throw new \RuntimeException('Malformed fork comparison file record.');
            }
            $sourcePath = $file['path'];
            self::relativePath($sourcePath);
            if (!str_starts_with($sourcePath, 'src/') || isset($expected[$sourcePath])) {
                throw new \RuntimeException('Fork comparison paths must be unique files under src/.');
            }
            $presence = $file['current_source_presence'] ?? null;
            $hash = $file['current_local_sha256'] ?? null;
            if (!array_key_exists('current_local_sha256', $file)
                || !in_array($presence, ['present', 'absent'], true)
                || ($presence === 'present' && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1))
                || ($presence === 'absent' && $hash !== null)) {
                throw new \RuntimeException('Fork comparison needs consistent current source presence and SHA256 fields.');
            }
            $expected[$sourcePath] = ['presence' => $presence, 'sha256' => $hash];
        }
        $sourceRoot = $root . '/' . $path . '/src';
        PrivateDirectory::assertNoSymlinks($sourceRoot);
        $actual = [];
        if (is_dir($sourceRoot)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    throw new \RuntimeException('Fork source inventory contains an unsupported file or symlink.');
                }
                if ($entry->isDir()) {
                    continue;
                }
                if (!$entry->isFile()) {
                    throw new \RuntimeException('Fork source inventory contains an unsupported file.');
                }
                $relative = 'src/' . substr($entry->getPathname(), strlen($sourceRoot) + 1);
                $hash = hash_file('sha256', $entry->getPathname());
                if ($hash === false) {
                    throw new \RuntimeException('Cannot hash fork source inventory.');
                }
                $actual[$relative] = $hash;
            }
        }
        foreach ($expected as $sourcePath => $record) {
            $hash = $actual[$sourcePath] ?? null;
            $matches = $record['sha256'] === $hash;
            if (!$matches) {
                $report->add('finding', $package, 'fork-source', $path . '/' . $sourcePath, 'Current fork source differs from its reviewed inventory.', ['reviewedPresence' => $record['presence'], 'reviewedSha256' => $record['sha256'], 'currentPresence' => $hash === null ? 'absent' : 'present', 'currentSha256' => $hash]);
            }
        }
        foreach (array_diff_key($actual, $expected) as $sourcePath => $hash) {
            $report->add('finding', $package, 'fork-source', $path . '/' . $sourcePath, 'Fork source file has no reviewed inventory entry.', ['currentSha256' => $hash]);
        }
        $report->add('evidence', $package, 'fork-source-inventory', $fork['comparison'], 'Compared every current fork source file with its reviewed presence and SHA256.', ['reviewedFiles' => count($expected), 'currentFiles' => count($actual)]);
    }

    private static function relativePath(string $path): void
    {
        Inventory::path($path);
        if (array_intersect(explode('/', $path), ['', '.']) !== []) {
            throw new \RuntimeException('Fork inventory paths must use canonical repository-relative components.');
        }
    }
}
