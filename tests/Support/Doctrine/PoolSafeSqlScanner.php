<?php

declare(strict_types=1);

namespace App\Tests\Support\Doctrine;

/**
 * Finds SQL in PHP string literals whose state outlives its transaction.
 *
 * Through a transaction-mode pooler a client holds a server connection only for one
 * transaction, so session state set by one client stays behind for whichever client gets that
 * connection next, and state a client expects to keep may be gone on its next transaction.
 * Flagged:
 * - session advisory locks and unlocks (the `_xact_` forms end with their transaction);
 * - `LISTEN` and `UNLISTEN`;
 * - SQL `PREPARE` and `DEALLOCATE`;
 * - `WITH HOLD` cursors;
 * - temporary tables without `ON COMMIT DROP`;
 * - `set_config(…)` whose third argument is not `true`;
 * - `SET` other than `SET LOCAL`, `SET TRANSACTION` and `SET CONSTRAINTS`. Parameter names
 *   count in lower case, as SQL writes them, or as `TimeZone`, `DateStyle` and `IntervalStyle`,
 *   so prose such as "Set APP_URL to …" does not.
 *
 * Statement keywords count where a statement starts: at the start of a literal that does not
 * continue a concatenation, after `;`, or after PL/pgSQL's `BEGIN`, `THEN`, `ELSE` and `LOOP`.
 * The scan works on tokens, so comments and docblocks never count.
 */
final class PoolSafeSqlScanner
{
    private const string STATEMENT_START = '(?:^|;|\b(?:BEGIN|THEN|ELSE|LOOP)\b)\s*';

    /** Rule => [pattern matched against each literal, what is wrong and what to use instead]. */
    private const array RULES = [
        'advisory lock' => [
            '/\bpg_(?:try_)?advisory_(?:lock|unlock)(?:_shared)?\s*\(|\bpg_advisory_unlock_all\s*\(/i',
            'takes or releases a session advisory lock; use pg_advisory_xact_lock or pg_try_advisory_xact_lock',
        ],
        'listen' => [
            '/' . self::STATEMENT_START . '(?:UN)?LISTEN\s+(?:\*|[A-Za-z_"][\w".]*)\s*(?:;|$)/i',
            'listens on a session channel; a pooled connection does not keep it',
        ],
        'prepare' => [
            '/' . self::STATEMENT_START . '(?:PREPARE\s+(?:TRANSACTION\b|[A-Za-z_"][\w"]*\s*(?:\(|AS\b))|DEALLOCATE\s+(?:PREPARE\s+)?(?:ALL\b|[A-Za-z_"]))/i',
            'prepares or frees a named SQL statement on the session; bind parameters through the driver instead',
        ],
        'hold cursor' => [
            '/\bWITH\s+HOLD\b/i',
            'declares a cursor that outlives its transaction; read the rows inside one transaction',
        ],
        'set' => [
            '/' . self::STATEMENT_START . 'SET\s+(?:(?!(?:LOCAL|TRANSACTION|CONSTRAINTS)\b)(?:(?-i:[a-z_][a-z0-9_.]*)|TimeZone|DateStyle|IntervalStyle|"[^"]+")\s*(?:=|\bTO\b)|SESSION\b|TIME\s+ZONE\b|ROLE\b|NAMES\b|SCHEMA\b|$)/i',
            'sets a session parameter; use SET LOCAL or set_config(…, true) inside a transaction',
        ],
    ];

    private const string TEMPORARY_TABLE = '/\bCREATE\s+(?:(?:GLOBAL|LOCAL)\s+)?TEMP(?:ORARY)?\s+TABLE\b/i';

    /**
     * @param list<string>       $directories relative to $root
     * @param array<string, int> $allowances  reviewed session constructs per path: the exact count the file may contain
     *
     * @return list<string> one line per violation
     */
    public static function scan(string $root, array $directories, array $allowances = []): array
    {
        $violations = [];
        $seen = [];
        foreach ($directories as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = substr($file->getPathname(), strlen($root) + 1);
                $source = file_get_contents($file->getPathname());
                if ($source === false) {
                    throw new \RuntimeException(sprintf('Cannot read %s.', $path));
                }
                $seen[$path] = true;
                array_push($violations, ...self::scanSource($path, $source, $allowances[$path] ?? 0));
            }
        }
        foreach (array_keys($allowances) as $path) {
            if (!isset($seen[$path])) {
                $violations[] = sprintf('%s: allowance for a file that no longer exists; remove it.', $path);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public static function scanSource(string $path, string $source, int $allowance = 0): array
    {
        $found = [];
        foreach (self::literals($source) as [$line, $text, $atStart]) {
            array_push($found, ...self::inspect($line, $text, $atStart));
        }
        if (count($found) === $allowance) {
            return [];
        }
        $violations = [];
        foreach ($found as [$line, $problem]) {
            $violations[] = sprintf(
                '%s:%d: %s%s.',
                $path,
                $line,
                $problem,
                $allowance > 0 ? sprintf(' (%d reviewed use(s) allowed, %d found)', $allowance, count($found)) : '',
            );
        }
        if ($found === []) {
            $violations[] = sprintf('%s: %d reviewed use(s) allowed but none found; lower the allowance.', $path, $allowance);
        }

        return $violations;
    }

    /**
     * String literal contents with their line and whether a statement can start at their first character.
     *
     * @return list<array{int, string, bool}>
     */
    private static function literals(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
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

    /** @return list<array{int, string}> */
    private static function inspect(int $line, string $text, bool $atStart): array
    {
        // A literal that continues a string cannot start a statement at its first character.
        $offset = $atStart ? 0 : 1;
        $subject = $atStart ? $text : "\x01" . $text;
        $found = [];
        foreach (self::RULES as [$pattern, $problem]) {
            if (preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$match, $at]) {
                    $found[] = [self::lineAt($line, $text, $at - $offset + strlen($match)), $problem];
                }
            }
        }
        if (preg_match_all(self::TEMPORARY_TABLE, $text, $matches, PREG_OFFSET_CAPTURE) > 0
            && preg_match('/\bON\s+COMMIT\s+DROP\b/i', $text) !== 1) {
            foreach ($matches[0] as [, $at]) {
                $found[] = [self::lineAt($line, $text, $at), 'creates a temporary table that outlives its transaction; add ON COMMIT DROP'];
            }
        }
        if (preg_match_all('/\bset_config\s*\(/i', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $at]) {
                $arguments = self::arguments($text, $at + strlen($match));
                if ($arguments === null || count($arguments) !== 3 || strtolower($arguments[2]) !== 'true') {
                    $found[] = [self::lineAt($line, $text, $at), 'calls set_config without is_local = true; the setting would outlive its transaction'];
                }
            }
        }
        usort($found, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $found;
    }

    /**
     * The top-level arguments of a call whose opening parenthesis ends just before $start, or null
     * when the call does not close inside the literal.
     *
     * @return list<string>|null
     */
    private static function arguments(string $text, int $start): ?array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $quoted = false;
        for ($i = $start, $length = strlen($text); $i < $length; ++$i) {
            $char = $text[$i];
            if ($quoted) {
                $quoted = $char !== "'";
            } elseif ($char === "'") {
                $quoted = true;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')' && $depth > 0) {
                --$depth;
            } elseif ($char === ')') {
                $arguments[] = trim($current);

                return $arguments;
            } elseif ($char === ',' && $depth === 0) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }

        return null;
    }

    private static function lineAt(int $line, string $text, int $offset): int
    {
        return $line + substr_count($text, "\n", 0, max(0, min($offset, strlen($text))));
    }
}
