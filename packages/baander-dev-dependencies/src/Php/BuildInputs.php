<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Php;

/**
 * Finds build inputs whose content the repository does not fix: an external image without a
 * digest, a fetched script piped into a shell, a download without a pinned SHA-256 check, a Git
 * fetch without a commit, and a PECL or PIE package fetched by name.
 *
 * Dockerfile instructions are read with their ARG defaults expanded. A check counts only inside
 * the RUN instruction that fetches, so no later layer can build on an unchecked file. An image
 * named by a build argument passes when the argument defaults to `scratch`, to a digest, or to a
 * project image (`baander…` without a registry), because the tooling fills it from the image
 * catalog, which is checked here as well.
 *
 * @phpstan-type Violation array{path: string, line: int, rule: string, reason: string, instruction: string}
 * @phpstan-type Image array{path: string, line: int, reference: string}
 */
final class BuildInputs
{
    private const array REASONS = [
        'image-digest' => 'External image is not pinned by digest.',
        'piped-installer' => 'A fetched script is piped into a shell.',
        'download-checksum' => 'A download is not checked against a SHA-256 pinned in the same instruction.',
        'git-commit' => 'A Git fetch is not pinned to a commit in the same instruction.',
        'package-fetch' => 'PECL or PIE fetches a package by name without a checksum; download its archive and check it.',
    ];

    private const string DIGESTED = '~^[^\s@]+@sha256:[0-9a-f]{64}$~D';

    /** Shell words that precede the command they run. */
    private const array PREFIXES = ['do', 'then', 'else', 'elif', 'if', 'while', 'until', '!', 'exec', 'sudo', 'env', 'time', 'command', 'nohup', 'nice'];

    /**
     * @param list<string> $dockerfiles repository-relative paths
     * @param array<string,mixed> $policy the `buildInputs` section of the dependency policy
     * @return array{violations: list<Violation>, images: list<Image>}
     */
    public static function scan(string $root, array $dockerfiles, array $policy): array
    {
        $violations = [];
        $images = [];
        foreach ($dockerfiles as $path) {
            Inventory::path($path);
            $scan = self::dockerfile($path, self::read($root, $path, 'Dockerfile'));
            array_push($violations, ...$scan['violations']);
            array_push($images, ...$scan['images']);
        }
        if (isset($policy['catalog'])) {
            $scan = self::catalog($root, (string) $policy['catalog'], array_keys($policy['locallyBuiltRoles'] ?? []));
            array_push($violations, ...$scan['violations']);
            array_push($images, ...$scan['images']);
        }
        if (isset($policy['workflows'])) {
            array_push($images, ...self::workflowImages($root, (string) $policy['workflows']));
        }
        return ['violations' => $violations, 'images' => $images];
    }

    /** @return array{violations: list<Violation>, images: list<Image>} */
    public static function dockerfile(string $path, string $text): array
    {
        /** @var array<string,string> $arguments */
        $arguments = [];
        /** @var array<string,true> $stages */
        $stages = [];
        $violations = [];
        $images = [];
        $violation = static function (int $line, string $rule, string $instruction) use ($path, &$violations): void {
            $violations[] = ['path' => $path, 'line' => $line, 'rule' => $rule, 'reason' => self::REASONS[$rule], 'instruction' => self::excerpt($instruction)];
        };
        $image = static function (int $line, string $value, string $instruction) use ($path, &$arguments, &$stages, &$images, $violation): void {
            $reference = self::expand($value, $arguments);
            if (strtolower($reference) === 'scratch' || isset($stages[strtolower($reference)]) || ctype_digit($reference)) {
                return;
            }
            if (preg_match(self::DIGESTED, $reference) === 1) {
                $images[] = ['path' => $path, 'line' => $line, 'reference' => $reference];
                return;
            }
            $argument = preg_match('/^\$\{?\w+\}?$/D', $value) === 1;
            if (!$argument || preg_match('~^baander[a-z0-9._-]*(?::[\w.-]+)?$~D', $reference) !== 1) {
                $violation($line, 'image-digest', $instruction);
            }
        };
        foreach (self::instructions($text) as ['line' => $line, 'keyword' => $keyword, 'value' => $value]) {
            $instruction = $keyword . ' ' . $value;
            if ($keyword === 'ARG') {
                preg_match_all('/([A-Za-z_]\w*)=("[^"]*"|\'[^\']*\'|\S*)/', $value, $matches, PREG_SET_ORDER);
                foreach ($matches as [, $name, $default]) {
                    $arguments[$name] = trim($default, '"\'');
                }
            } elseif ($keyword === 'FROM') {
                $words = array_values(array_filter(preg_split('/\s+/', trim($value)) ?: [], static fn(string $word): bool => !str_starts_with($word, '--')));
                $image($line, $words[0] ?? '', $instruction);
                if (strtoupper($words[1] ?? '') === 'AS' && isset($words[2])) {
                    $stages[strtolower($words[2])] = true;
                }
            } elseif ($keyword === 'COPY' || $keyword === 'ADD') {
                $words = preg_split('/\s+/', trim($value)) ?: [];
                $flags = array_filter($words, static fn(string $word): bool => str_starts_with($word, '--'));
                foreach ($flags as $flag) {
                    if (str_starts_with($flag, '--from=')) {
                        $image($line, substr($flag, 7), $instruction);
                    }
                }
                $sources = array_slice(array_values(array_diff($words, $flags)), 0, -1);
                $remote = array_filter($sources, static fn(string $source): bool => preg_match('~^(?:https?|git)://|^git@~', $source) === 1);
                if ($keyword === 'ADD' && $remote !== [] && preg_grep('/^--checksum=sha256:[0-9a-f]{64}$/D', $flags) === []) {
                    $violation($line, 'download-checksum', $instruction);
                }
            } elseif ($keyword === 'RUN') {
                $command = self::expand(preg_replace('/^(?:\s*--\S+)*\s*/', '', $value) ?? $value, $arguments);
                foreach (self::runRules($command) as $rule) {
                    $violation($line, $rule, $instruction);
                }
            }
        }
        return ['violations' => $violations, 'images' => $images];
    }

    /**
     * The rules one RUN instruction breaks.
     *
     * @return list<string>
     */
    private static function runRules(string $command): array
    {
        if (preg_match('~\b(?:curl|wget)\b[^|;&\n]*\|\s*(?:sudo\s+)?(?:/usr)?(?:/bin/)?(?:ba|da|z|k)?sh\b~', $command) === 1
            || preg_match('~\b(?:ba|da|z|k)?sh\b[^;&|\n]*(?:\$\(|<\()\s*(?:curl|wget)\b~', $command) === 1) {
            return ['piped-installer'];
        }
        $downloads = 0;
        $checks = 0;
        $gitFetches = 0;
        $packages = false;
        foreach (self::commands($command) as [$name, $arguments]) {
            $subcommand = self::subcommand($arguments);
            if ($name === 'curl' || $name === 'wget') {
                ++$downloads;
            } elseif ($name === 'sha256sum' && preg_grep('/^(?:--check|-[a-z]*c[a-z]*)$/D', $arguments) !== []) {
                ++$checks;
            } elseif ($name === 'git' && in_array($subcommand, ['clone', 'fetch'], true)) {
                ++$gitFetches;
            } elseif (($name === 'pecl' && in_array($subcommand, ['install', 'bundle', 'download'], true))
                || ($name === 'pie' && in_array($subcommand, ['install', 'download', 'build'], true))) {
                $packages = true;
            }
        }
        $rules = [];
        if ($downloads > 0 && ($checks < $downloads || preg_match('/\b[0-9a-f]{64}\b/', $command) !== 1)) {
            $rules[] = 'download-checksum';
        }
        if ($gitFetches > 0 && preg_match('/\b[0-9a-f]{40}\b/', $command) !== 1) {
            $rules[] = 'git-commit';
        }
        if ($packages) {
            $rules[] = 'package-fetch';
        }
        return $rules;
    }

    /**
     * Instructions with their first line; continuation lines are joined and comment lines inside
     * them dropped, as the Dockerfile parser does.
     *
     * @return list<array{line: int, keyword: string, value: string}>
     */
    private static function instructions(string $text): array
    {
        $instructions = [];
        $pending = null;
        foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $continued = preg_match('/\\\\\s*$/', $line) === 1;
            $body = $continued ? (preg_replace('/\\\\\s*$/', '', $line) ?? $line) : $line;
            $pending = $pending === null ? ['line' => $index + 1, 'text' => $body] : ['line' => $pending['line'], 'text' => $pending['text'] . ' ' . $body];
            if (!$continued) {
                $instructions[] = $pending;
                $pending = null;
            }
        }
        if ($pending !== null) {
            $instructions[] = $pending;
        }
        return array_map(static function (array $instruction): array {
            [$keyword, $value] = preg_split('/\s+/', trim($instruction['text']), 2) + [1 => ''];
            return ['line' => $instruction['line'], 'keyword' => strtoupper($keyword), 'value' => $value];
        }, $instructions);
    }

    /**
     * The simple commands of a shell line, each as its command name and arguments. Quoted words
     * are masked first, so text inside them is never read as a command.
     *
     * @return list<array{string, list<string>}>
     */
    private static function commands(string $command): array
    {
        $masked = preg_replace('/\'[^\']*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", $command) ?? $command;
        $commands = [];
        foreach (preg_split('/&&|\|\||[;|&(){}`\n]/', $masked) ?: [] as $segment) {
            $words = array_values(array_filter(preg_split('/\s+/', trim($segment)) ?: [], static fn(string $word): bool => $word !== ''));
            while ($words !== [] && (in_array($words[0], self::PREFIXES, true) || preg_match('/^[A-Za-z_]\w*=/', $words[0]) === 1)) {
                array_shift($words);
            }
            if ($words !== []) {
                $commands[] = [basename(array_shift($words)), $words];
            }
        }
        return $commands;
    }

    /** @param list<string> $arguments */
    private static function subcommand(array $arguments): ?string
    {
        for ($index = 0; $index < count($arguments); ++$index) {
            if (in_array($arguments[$index], ['-C', '-c', '-d'], true)) {
                ++$index;
            } elseif (!str_starts_with($arguments[$index], '-')) {
                return $arguments[$index];
            }
        }
        return null;
    }

    /** @param array<string,string> $arguments */
    private static function expand(string $value, array $arguments): string
    {
        return preg_replace_callback(
            '/\$\{(\w+)(?::?[-+][^}]*)?\}|\$(\w+)/',
            static fn(array $match): string => $arguments[$match[1] !== '' ? $match[1] : $match[2]] ?? $match[0],
            $value,
        ) ?? $value;
    }

    /**
     * @param list<string> $locallyBuilt roles whose images the tooling builds and never pulls
     * @return array{violations: list<Violation>, images: list<Image>}
     */
    private static function catalog(string $root, string $path, array $locallyBuilt): array
    {
        Inventory::path($path);
        $text = self::read($root, $path, 'image catalog');
        $catalog = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($catalog) || !is_array($catalog['images'] ?? null)) {
            throw new \RuntimeException('The image catalog ' . $path . ' lists no images.');
        }
        $violations = [];
        $images = [];
        foreach ($catalog['images'] as $role => $selection) {
            $reference = is_array($selection) ? ($selection['reference'] ?? null) : null;
            if (!is_string($role) || !is_string($reference)) {
                throw new \RuntimeException('The image catalog ' . $path . ' has a role without a reference.');
            }
            if (in_array($role, $locallyBuilt, true)) {
                continue;
            }
            $offset = strpos($text, '"' . $role . '"');
            $line = $offset === false ? 0 : substr_count($text, "\n", 0, $offset) + 1;
            if (preg_match(self::DIGESTED, $reference) === 1) {
                $images[] = ['path' => $path, 'line' => $line, 'reference' => $reference];
            } else {
                $violations[] = ['path' => $path, 'line' => $line, 'rule' => 'image-digest', 'reason' => self::REASONS['image-digest'], 'instruction' => $role];
            }
        }
        return ['violations' => $violations, 'images' => $images];
    }

    /**
     * Digest-pinned image references in the workflow files, for the drift report. The workflow
     * contract test owns the workflow rules.
     *
     * @return list<Image>
     */
    private static function workflowImages(string $root, string $directory): array
    {
        Inventory::path($directory);
        $images = [];
        $files = glob($root . '/' . $directory . '/*.{yml,yaml}', GLOB_BRACE) ?: throw new \RuntimeException('No workflow files in ' . $directory);
        sort($files);
        foreach ($files as $file) {
            $path = $directory . '/' . basename($file);
            $text = self::read($root, $path, 'workflow');
            preg_match_all('~[a-z0-9][a-z0-9._/:-]*@sha256:[0-9a-f]{64}~', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$reference, $offset]) {
                $images[] = ['path' => $path, 'line' => substr_count($text, "\n", 0, $offset) + 1, 'reference' => $reference];
            }
        }
        return $images;
    }

    private static function excerpt(string $instruction): string
    {
        $line = preg_replace('/\s+/', ' ', $instruction) ?? $instruction;
        return strlen($line) > 240 ? substr($line, 0, 240) . '...' : $line;
    }

    private static function read(string $root, string $path, string $kind): string
    {
        $text = @file_get_contents($root . '/' . $path);
        if ($text === false) {
            throw new \RuntimeException('Cannot read the ' . $kind . ' ' . $path);
        }
        return $text;
    }
}
