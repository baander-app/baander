<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use DateTimeImmutable;
use RuntimeException;

/**
 * The most recent commit date of each repository path, read from one `git log` pass.
 * A page's last modification is the newest commit touching any of its source files or directories.
 */
final readonly class SourceHistory
{
    /** Arguments that produce the input `fromGitLog()` expects; append `--` and the paths to cover. */
    public const array GIT_LOG = ['git', 'log', '--format=%x1e%cI', '--name-only'];

    /** @param array<string, DateTimeImmutable> $dates repository path => latest commit date */
    private function __construct(private array $dates) {}

    public static function fromGitLog(string $output): self
    {
        $dates = [];
        foreach (explode("\x1e", $output) as $commit) {
            $lines = explode("\n", $commit)
                |> (static fn(array $lines): array => array_map(trim(...), $lines))
                |> (static fn(array $lines): array => array_filter($lines, static fn(string $line): bool => $line !== ''))
                |> array_values(...);
            if ($lines === []) {
                continue;
            }
            $date = array_shift($lines) |> self::commitDate(...);
            foreach ($lines as $path) {
                // git log lists newer commits first, so the first date seen for a path is its latest.
                $dates[$path] ??= $date;
            }
        }

        return new self($dates);
    }

    /**
     * @param list<string> $paths files or directories relative to the repository root
     */
    public function lastModified(array $paths): ?string
    {
        $roots = array_map(static fn(string $path): string => trim($path, '/'), $paths);
        $covered = static fn(string $file): bool => array_any(
            $roots,
            static fn(string $root): bool => $file === $root || str_starts_with($file, $root . '/'),
        );

        return array_filter($this->dates, $covered, ARRAY_FILTER_USE_KEY)
            |> (static fn(array $dates): ?DateTimeImmutable => $dates === [] ? null : max($dates))
            |> (static fn(?DateTimeImmutable $date): ?string => $date?->format(DATE_ATOM));
    }

    private static function commitDate(string $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat(DATE_ATOM, $value)
            ?: throw new RuntimeException('Unexpected commit date in source history: ' . $value);
    }
}
