<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Build;

use Baander\Tooling\Development\Environment;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\WorkflowError;
use Baander\Tooling\Inventory\SafeTar;

final class Source
{
    private const string PROSE_AUDIT = '.agents/skills/prose-fix/scripts/audit_rewrite.php';

    private const array EXCLUDED = [
        '.git',
        '.agents',
        '.codex',
        '.claude',
        '.idea',
        '.reli',
        'vendor',
        'node_modules',
        'var',
        '__pycache__',
        '.phpunit.cache',
    ];

    public static function snapshotWorktree(Runner $runner, string $source, ?string $destination): string
    {
        $source = Environment::resolvedPath($source);
        if ($destination !== null && (file_exists($destination) || !mkdir($destination, 0700, true))) {
            throw new WorkflowError('Source snapshot destination must not exist.');
        }
        $names = $runner->run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd: $source)->stdout
            |> (static fn(string $output): array => explode("\0", $output))
            |> array_unique(...);
        sort($names, SORT_STRING);
        $digest = hash_init('sha256');
        foreach ($names as $name) {
            $base = basename($name);
            $excluded = array_intersect(self::EXCLUDED, explode('/', $name));
            if (str_starts_with($name, 'relay/third_party/inventory/go/licenses/')) {
                $excluded = array_diff($excluded, ['vendor']);
            }
            if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)
                || ($name !== self::PROSE_AUDIT && $excluded !== [])
                || str_starts_with($name, 'config/secrets/') || $name === 'auth.json'
                || (str_starts_with($base, '.env') && str_contains($base, '.local'))
                || (str_starts_with($name, 'docker/dev/') && str_ends_with($name, '.key'))) {
                continue;
            }
            $original = $source . '/' . $name;
            if (!file_exists($original) && !is_link($original)) {
                continue;
            }
            if ($name === self::PROSE_AUDIT && self::resolveLinks($original) !== $original) {
                throw new WorkflowError('The prose audit helper must be a regular worktree file without symlink components.');
            }
            $target = $destination === null ? null : $destination . '/' . $name;
            if ($target !== null) {
                $target |> dirname(...) |> self::createSnapshotDirectory(...);
            }
            if (is_link($original)) {
                $resolved = self::resolveLinks($original);
                if ($resolved !== $source && !str_starts_with($resolved, $source . '/')) {
                    throw new WorkflowError('Source contains a symlink outside the worktree: ' . $name);
                }
                $content = readlink($original);
                if ($content === false || str_starts_with($content, '/')) {
                    throw new WorkflowError('Source contains an absolute symlink: ' . $name);
                }
                if ($target !== null && !symlink($content, $target)) {
                    throw new WorkflowError('Cannot copy source symlink: ' . $name);
                }
            } elseif (is_file($original)) {
                if ($base === '.env' || (str_starts_with($base, '.env.') && !str_ends_with($base, '.example'))) {
                    $template = $runner->run(['git', 'show', 'HEAD:' . $name], cwd: $source, check: false);
                    if ($template->exitCode !== 0) {
                        continue;
                    }
                    $content = $template->stdout;
                } else {
                    $content = file_get_contents($original);
                    if ($content === false) {
                        throw new WorkflowError('Cannot read source snapshot entry: ' . $name);
                    }
                }
                if ($target !== null && (file_put_contents($target, $content) === false || !chmod($target, fileperms($original) & 0777))) {
                    throw new WorkflowError('Cannot copy source snapshot entry: ' . $name);
                }
            } else {
                throw new WorkflowError('Source snapshot contains an unsupported entry: ' . $name);
            }
            $mode = lstat($original);
            if ($mode === false) {
                throw new WorkflowError('Source snapshot entry disappeared: ' . $name);
            }
            hash_update($digest, $name . "\0" . ($mode['mode'] & 0777) . "\0" . $content . "\0");
        }
        return hash_final($digest);
    }

    private static function createSnapshotDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        $directory |> dirname(...) |> self::createSnapshotDirectory(...);
        if (!mkdir($directory, 0755) || !chmod($directory, 0755)) {
            throw new WorkflowError('Cannot create source snapshot directory.');
        }
    }

    private static function resolveLinks(string $path): string
    {
        $pending = explode('/', $path);
        $resolved = [];
        $links = 0;
        while ($pending !== []) {
            $part = array_shift($pending);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
                continue;
            }
            $candidate = '/' . implode('/', [...$resolved, $part]);
            if (is_link($candidate)) {
                $link = readlink($candidate);
                if ($link === false || ++$links > 40) {
                    throw new WorkflowError('Source contains an unresolvable symlink: ' . $path);
                }
                if (str_starts_with($link, '/')) {
                    $resolved = [];
                }
                $pending = [...explode('/', $link), ...$pending];
            } else {
                $resolved[] = $part;
            }
        }
        return '/' . implode('/', $resolved);
    }

    /** @return array{string,string} */
    public static function checkoutRevision(Runner $runner, string $remote, string $managed, string $destination): array
    {
        if (!file_exists($managed)) {
            if (!is_dir(dirname($managed))) {
                mkdir(dirname($managed), 0700, true);
            }
            $temporary = dirname($managed) . '/.clone-' . bin2hex(random_bytes(12));
            mkdir($temporary, 0700);
            try {
                try {
                    $runner->run(['git', 'clone', '--no-checkout', '--no-hardlinks', '--', $remote, $temporary . '/checkout'], timeout: 300);
                } catch (WorkflowError $error) {
                    throw new SourceUnavailable('Source clone is unavailable; retry source acquisition later.', previous: $error);
                }
                if (!rename($temporary . '/checkout', $managed)) {
                    throw new WorkflowError('Cannot publish the managed checkout.');
                }
            } finally {
                self::remove($temporary);
            }
        }
        if (trim($runner->run(['git', 'remote', 'get-url', 'origin'], cwd: $managed)->stdout) !== $remote) {
            throw new WorkflowError('Managed checkout belongs to a different remote.');
        }
        try {
            $runner->run(['git', 'fetch', '--no-tags', 'origin', '+refs/heads/master:refs/remotes/origin/master'], cwd: $managed, timeout: 300);
        } catch (WorkflowError $error) {
            throw new SourceUnavailable('Source fetch is unavailable; retry source acquisition later.', previous: $error);
        }
        $revision = trim($runner->run(['git', 'rev-parse', '--verify', 'refs/remotes/origin/master^{commit}'], cwd: $managed)->stdout);
        if (file_exists($destination) || !mkdir($destination, 0700, true)) {
            throw new WorkflowError('Source release destination must not exist.');
        }
        $archive = dirname($destination) . '/.source-' . bin2hex(random_bytes(12)) . '.tar';
        try {
            $runner->run(['git', 'archive', '--format=tar', '--output=' . $archive, $revision], cwd: $managed);
            SafeTar::extract($archive, $destination, allowAbsoluteLinks: false);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
        return [$destination, $revision];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
