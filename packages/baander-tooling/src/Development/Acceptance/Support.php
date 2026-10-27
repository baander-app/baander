<?php

declare(strict_types=1);

namespace Baander\Tooling\Development\Acceptance;

use Baander\Tooling\Development\Environment;
use Baander\Tooling\Process\ProcessResult;
use Baander\Tooling\Process\ProcessRunner;
use Baander\Tooling\Process\ProcessSpec;

final class Support
{
    /** @param list<string> $argv
     * @param array<string,string|bool> $defaults
     * @param list<string> $flags
     * @param list<string> $required
     * @return array<string,string|bool>
     */
    public static function options(array $argv, array $defaults, array $flags = [], array $required = []): array
    {
        for ($index = 0; $index < count($argv); $index++) {
            [$name, $inline] = array_pad(explode('=', $argv[$index], 2), 2, null);
            $name = substr($name, 2);
            self::argument(
                str_starts_with($argv[$index], '--') && array_key_exists($name, $defaults),
                'Unknown option: ' . $argv[$index],
            );
            if (in_array($name, $flags, true)) {
                self::argument($inline === null, 'Option --' . $name . ' does not accept a value.');
                $defaults[$name] = true;
            } else {
                $value = $inline ?? ($argv[++$index] ?? null);
                self::argument(
                    $value !== null && !str_starts_with($value, '--'),
                    'Missing value for --' . $name . '.',
                );
                $defaults[$name] = $value;
            }
        }
        foreach ($required as $name) {
            self::argument(($defaults[$name] ?? '') !== '', 'Required option: --' . $name);
        }
        if (isset($defaults['timeout'])) {
            self::argument(
                ctype_digit((string) $defaults['timeout']) && (int) $defaults['timeout'] > 0,
                '--timeout must be a positive integer.',
            );
        }
        return $defaults;
    }

    private static function argument(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /** @param non-empty-list<string> $argv
     * @param array<string,string> $environment
     */
    public static function run(
        array $argv,
        ?string $cwd = null,
        ?string $input = null,
        ?float $timeout = null,
        bool $check = true,
        array $environment = [],
        bool $passthrough = false,
    ): ProcessResult {
        $result = new ProcessRunner()->run(new ProcessSpec(
            argv: $argv,
            cwd: $cwd,
            environment: array_replace(getenv(), $environment),
            timeout: $timeout,
            stdin: $input,
            passthrough: $passthrough,
            processGroup: true,
        ));
        if ($check && ($result->exitCode !== 0 || $result->timedOut || $result->cancelled)) {
            throw new CommandFailure(
                basename($argv[0]) . ' failed with status ' . $result->exitCode . ': ' . substr($result->stderr, -2000),
                $result->exitCode === 0 ? 1 : $result->exitCode,
            );
        }
        return $result;
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            self::removeTree($entry->getPathname());
        }
        rmdir($path);
    }

    public static function temporary(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        self::check(mkdir($path, 0700), 'Cannot create disposable directory.');
        return $path;
    }

    public static function path(string $path): string
    {
        return Environment::resolvedPath($path);
    }
    public static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
