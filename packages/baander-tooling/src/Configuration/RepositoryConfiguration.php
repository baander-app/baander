<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

use Baander\Tooling\Process\{CommandExecutor, ProcessSpec, CompletionPolicy};

final class RepositoryConfiguration
{
    /** @phpstan-impure */
    public static function discover(CommandExecutor $runner, string $root): ?string
    {
        $path = $root . '/dev.local.json';
        if (!file_exists($path)) {
            if (!file_exists($root . '/.git')) {
                return null;
            }
            $metadata = $runner->run(new ProcessSpec(['git', 'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir'], cwd: $root, processGroup: true), new CompletionPolicy(null));
            $directories = explode("\n", trim($metadata->stdout));
            if ($metadata->exitCode !== 0 || count($directories) !== 2 || $directories[0] === $directories[1]) {
                return null;
            }
            $worktrees = $runner->run(new ProcessSpec(['git', 'worktree', 'list', '--porcelain', '-z'], cwd: $root, processGroup: true));
            $primary = explode("\0", $worktrees->stdout)[0];
            $checkout = substr($primary, strlen('worktree '));
            $configured = $runner->run(new ProcessSpec(['git', 'config', '--get', 'core.worktree'], cwd: $root, processGroup: true), new CompletionPolicy(null));
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
            $candidate = $runner->run(new ProcessSpec(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], cwd: $checkout, processGroup: true), new CompletionPolicy(null));
            if (
                $candidate->exitCode !== 0
                || ($candidate->stdout |> trim(...) |> \Baander\Tooling\Filesystem\Paths::resolvedPath(...)) !== \Baander\Tooling\Filesystem\Paths::resolvedPath($directories[1])
            ) {
                return null;
            }
            $path = $checkout . '/dev.local.json';
            if (!file_exists($path)) {
                return null;
            }
        }
        return $path;
    }

    /**
     * Read the chosen file without checking library source directories.
     *
     * @param array<string,string> $environment
     * @return array<string,mixed>
     * @phpstan-impure
     */
    public static function local(CommandExecutor $runner, string $root, array $environment): array
    {
        if (isset($environment['CI']) && !in_array(strtolower(trim($environment['CI'])), ['', '0', 'false', 'no', 'off'], true)) {
            return [];
        }
        $path = self::discover($runner, $root);
        if ($path === null) {
            return [];
        }
        return LocalFile::read($path);
    }

}
