<?php

declare(strict_types=1);

namespace App\Tests\Support\Doctrine;

/**
 * Finds instant binds that are zone-less or whole-second in PHP source.
 *
 * Two checks:
 * - anywhere: a bind typed with Doctrine's mutable or `datetimetz` names. `datetime_immutable`
 *   is the project's instant type; `datetimetz_immutable` is registered to the same class only
 *   so that nothing silently drops microseconds, and code names the instant type directly.
 * - in database code (a file that uses DBAL, PDO or the entity manager): a date format that
 *   drops the offset or the fraction (`Y-m-d H:i:s`, `DATE_ATOM`, `'c'`, …). Those files may
 *   still format an instant for output; such reviewed sites are passed in as allowances.
 *
 * The scan works on tokens, so comments and docblocks never count.
 */
final class InstantBindScanner
{
    private const array FORBIDDEN_TYPE_CONSTANTS = ['DATETIMETZ_IMMUTABLE', 'DATETIME_MUTABLE', 'DATETIMETZ_MUTABLE'];

    private const array FORBIDDEN_TYPE_NAMES = ['datetimetz_immutable', 'datetime', 'datetimetz'];

    /** `DateTimeInterface` constants and their global `DATE_*` twins; none carries microseconds. */
    private const array WHOLE_SECOND_FORMAT_CONSTANTS = [
        'ATOM', 'COOKIE', 'ISO8601', 'ISO8601_EXPANDED', 'RFC822', 'RFC850', 'RFC1036', 'RFC1123',
        'RFC7231', 'RFC2822', 'RFC3339', 'RFC3339_EXTENDED', 'RSS', 'W3C',
    ];

    /** Single-character formats that render an instant without a fraction. */
    private const array WHOLE_SECOND_FORMAT_LETTERS = ['c', 'r', 'U'];

    private const string DATABASE_CODE = '/^\\\\?(Doctrine\\\\DBAL\\\\|Doctrine\\\\ORM\\\\(EntityManagerInterface|EntityManager|QueryBuilder|NativeQuery|Query)$|PDO$|PDOStatement$)/';

    /**
     * @param array<string, int> $allowances reviewed non-bind uses per path: the exact count of
     *                                       whole-second or zone-less formats the file may contain
     *
     * @return list<string> one line per violation
     */
    public static function scan(string $root, string $directory, array $allowances = [], string $typeRegistrar = ''): array
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
        $violations = [];
        $seen = [];
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
            array_push($violations, ...self::scanSource($path, $source, $allowances[$path] ?? 0, $path === $typeRegistrar));
        }
        foreach (array_keys($allowances) as $path) {
            if (!isset($seen[$path])) {
                $violations[] = sprintf('%s: allowance for a file that no longer exists; remove it.', $path);
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public static function scanSource(string $path, string $source, int $allowance = 0, bool $registersTypes = false): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        $violations = [];
        if (!$registersTypes) {
            foreach (self::forbiddenTypes($tokens) as [$line, $text]) {
                $violations[] = sprintf('%s:%d: binds %s; bind instants as Types::DATETIME_IMMUTABLE.', $path, $line, $text);
            }
        }

        if (!self::isDatabaseCode($tokens)) {
            if ($allowance > 0) {
                $violations[] = sprintf('%s: no longer database code; remove its allowance.', $path);
            }

            return $violations;
        }

        $formats = self::inexactFormats($tokens);
        if (count($formats) !== $allowance) {
            foreach ($formats as [$line, $text]) {
                $violations[] = sprintf(
                    '%s:%d: formats an instant as %s in database code; bind it as Types::DATETIME_IMMUTABLE or a Y-m-d H:i:s.uP string%s.',
                    $path,
                    $line,
                    $text,
                    $allowance > 0 ? sprintf(' (%d reviewed output use(s) allowed, %d found)', $allowance, count($formats)) : '',
                );
            }
            if ($formats === []) {
                $violations[] = sprintf('%s: %d reviewed output use(s) allowed but none found; lower the allowance.', $path, $allowance);
            }
        }

        return $violations;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return list<array{int, string}>
     */
    private static function forbiddenTypes(array $tokens): array
    {
        $found = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(T_STRING) && in_array($token->text, self::FORBIDDEN_TYPE_CONSTANTS, true)
                && ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON) && self::endsWith($tokens[$i - 2] ?? null, 'Types')) {
                $found[] = [$token->line, 'Types::' . $token->text];
            }
            // A type name passed as a string: `'createdAt' => 'datetimetz_immutable'` or `type: 'datetime'`.
            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && in_array(self::literal($token), self::FORBIDDEN_TYPE_NAMES, true)
                && (($tokens[$i - 1] ?? null)?->is(T_DOUBLE_ARROW) || ($tokens[$i - 1] ?? null)?->text === ':')) {
                $found[] = [$token->line, $token->text];
            }
        }

        return $found;
    }

    /**
     * @param list<\PhpToken> $tokens
     *
     * @return list<array{int, string}>
     */
    private static function inexactFormats(array $tokens): array
    {
        $found = [];
        foreach ($tokens as $i => $token) {
            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($token->text, '\\');
                if (str_starts_with($name, 'DATE_') && in_array(substr($name, 5), self::WHOLE_SECOND_FORMAT_CONSTANTS, true)
                    && !($tokens[$i - 1] ?? null)?->is([T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                    $found[] = [$token->line, $name];
                }
                if (in_array($name, self::WHOLE_SECOND_FORMAT_CONSTANTS, true)
                    && ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON) && self::endsWith($tokens[$i - 2] ?? null, 'DateTimeInterface', 'DateTimeImmutable', 'DateTime')) {
                    $found[] = [$token->line, $tokens[$i - 2]->text . '::' . $name];
                }
            }
            if (!$token->is(T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }
            $format = self::literal($token);
            // A time format is exact only with microseconds and, after them, an offset or zone.
            if (str_contains($format, 'H:i:s') && preg_match('/H:i:s\.u.*[PpOeT]/', $format) !== 1) {
                $found[] = [$token->line, $token->text];
            }
            if (in_array($format, self::WHOLE_SECOND_FORMAT_LETTERS, true) && ($tokens[$i - 1] ?? null)?->text === '('
                && in_array(($tokens[$i - 2] ?? null)?->text, ['format', 'date', 'gmdate'], true)) {
                $found[] = [$token->line, $token->text];
            }
        }

        return $found;
    }

    /** @param list<\PhpToken> $tokens */
    private static function isDatabaseCode(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING]) && preg_match(self::DATABASE_CODE, $token->text) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function endsWith(?\PhpToken $token, string ...$names): bool
    {
        if ($token === null || !$token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return false;
        }
        $parts = explode('\\', $token->text);

        return in_array(end($parts), $names, true);
    }

    private static function literal(\PhpToken $token): string
    {
        return stripcslashes(substr($token->text, 1, -1));
    }
}
