<?php

declare(strict_types=1);

namespace App\Tests\Support\Doctrine;

/**
 * Finds SQL in PHP string literals that runs PGroonga's cleanup of leftover Groonga objects:
 * `ANALYZE`, `VACUUM` with or without `ANALYZE`, and `pgroonga_vacuum()`. `ANALYZE` or `VACUUM` of
 * a table with a PGroonga index runs the cleanup, which removes the leftover objects of every
 * dropped PGroonga index in the database.
 *
 * `ANALYZE` and `VACUUM` count where a statement starts: at the start of a literal that does not
 * continue a concatenation, after `;`, or after PL/pgSQL's `BEGIN`, `THEN`, `ELSE` and `LOOP`, so
 * `EXPLAIN ANALYZE` and `EXPLAIN (ANALYZE, …)` do not. They count in upper or lower case, as SQL
 * writes them, so a sentence such as "Analyze registry and verify …" does not. A call of
 * `pgroonga_vacuum` counts anywhere. A file that calls `PgroongaLeftovers::clear()` passes whatever
 * it runs. The scan works on tokens, so comments and docblocks never count, and neither does a
 * mention of the helper in them.
 */
final class PgroongaCleanupScanner
{
    private const string HELPER_CLASS = 'PgroongaLeftovers';

    private const string HELPER_METHOD = 'clear';

    private const string STATEMENT_START = '(?:^|;|\b(?:BEGIN|THEN|ELSE|LOOP)\b)\s*\K';

    /** What the statement does => the pattern matched against each literal. */
    private const array CLEANUPS = [
        'runs ANALYZE' => '/' . self::STATEMENT_START . '(?-i:ANALYZE|analyze)\b/i',
        'runs VACUUM' => '/' . self::STATEMENT_START . '(?-i:VACUUM|vacuum)\b/i',
        'calls pgroonga_vacuum' => '/\bpgroonga_vacuum\s*\(/i',
    ];

    /**
     * @param array<string, int> $reviewed reviewed cleanups per path: the exact count the file may contain
     * @param string             $helper   the helper's own file, whose cleanup runs under its locks
     *
     * @return list<string> one line per violation
     */
    public static function scan(string $root, string $directory, array $reviewed, string $helper): array
    {
        $violations = [];
        $seen = [];
        foreach (self::phpFiles($root . '/' . $directory) as $file) {
            $path = substr($file, strlen($root) + 1);
            $seen[$path] = true;
            if ($path === $helper) {
                continue;
            }
            $source = file_get_contents($file);
            if ($source === false) {
                throw new \RuntimeException(sprintf('Cannot read %s.', $path));
            }
            array_push($violations, ...self::scanSource($path, $source, $reviewed[$path] ?? 0));
        }
        foreach (array_keys(array_diff_key($reviewed, $seen)) as $path) {
            $violations[] = sprintf('%s: reviewed cleanups listed for a file that no longer exists; remove the entry.', $path);
        }

        return $violations;
    }

    /** @return list<string> */
    public static function scanSource(string $path, string $source, int $reviewed = 0): array
    {
        $tokens = preg_match('/analyze|vacuum/i', $source) === 1 ? self::tokens($source) : [];
        if (self::callsHelper($tokens)) {
            return [];
        }
        $found = self::cleanups($tokens);
        if (count($found) === $reviewed) {
            return [];
        }
        if ($found === []) {
            return [sprintf('%s: %d reviewed cleanup(s) listed but none found; lower the count.', $path, $reviewed)];
        }

        return array_map(
            static fn (array $cleanup): string => sprintf(
                '%s:%d: %s without PgroongaLeftovers::clear() first%s.',
                $path,
                $cleanup[0],
                $cleanup[1],
                $reviewed > 0 ? sprintf(' (%d reviewed cleanup(s) listed, %d found)', $reviewed, count($found)) : '',
            ),
            $found,
        );
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !$entry->isDir() || $entry->getFilename() !== 'node_modules',
        ));
        $files = [];
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    /** @return list<\PhpToken> */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
    }

    /** @param list<\PhpToken> $tokens */
    private static function callsHelper(array $tokens): bool
    {
        return array_any(
            $tokens,
            static fn (\PhpToken $token, int $i): bool => $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && array_last(explode('\\', $token->text)) === self::HELPER_CLASS
                && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON) === true
                && ($tokens[$i + 2] ?? null)?->text === self::HELPER_METHOD
                && ($tokens[$i + 3] ?? null)?->text === '(',
        );
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return list<array{int, string}> the line of each cleanup and what it does, in source order
     */
    private static function cleanups(array $tokens): array
    {
        $found = [];
        foreach (self::literals($tokens) as [$line, $text, $atStart]) {
            // A literal that continues a string cannot start a statement at its first character.
            $offset = $atStart ? 0 : 1;
            $subject = $atStart ? $text : "\x01" . $text;
            foreach (self::CLEANUPS as $cleanup => $pattern) {
                preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as [, $at]) {
                    $found[] = [self::lineAt($line, $text, $at - $offset), $cleanup];
                }
            }
        }
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $found;
    }

    /**
     * String literal contents with their line and whether a statement can start at their first character.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return list<array{int, string, bool}>
     */
    private static function literals(array $tokens): array
    {
        $literals = [];
        foreach ($tokens as $i => $token) {
            $previous = $tokens[$i - 1] ?? null;
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $literals[] = [$token->line, substr($token->text, 1, -1), $previous?->text !== '.'];
            } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
                // Only the first part of an interpolated string or heredoc starts the literal.
                $literals[] = [$token->line, $token->text, $previous !== null && ($previous->text === '"' || $previous->is(T_START_HEREDOC))];
            }
        }

        return $literals;
    }

    private static function lineAt(int $line, string $text, int $offset): int
    {
        $boundedOffset = min($offset, strlen($text));

        return $line + substr_count($text, "\n", 0, max(0, $boundedOffset));
    }
}
