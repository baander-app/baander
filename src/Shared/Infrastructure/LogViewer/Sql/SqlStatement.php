<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Sql;

use InvalidArgumentException;

/** Checks statement boundaries; SQLite still parses and authorizes the SQL itself. */
final class SqlStatement
{
    public static function validate(string $sql): void
    {
        if (strlen($sql) > 65536 || trim($sql) === '' || str_contains($sql, "\0")) {
            throw new InvalidArgumentException('SQL must contain one statement of at most 64 KiB.');
        }
        $ended = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];
            if (ctype_space($char)) {
                continue;
            }
            if (substr($sql, $i, 2) === '--') {
                $end = strpos($sql, "\n", $i + 2);
                $i = $end === false ? $length : $end;
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new InvalidArgumentException('SQL has an unterminated comment.');
                }
                $i = $end + 1;
                continue;
            }
            if ($ended) {
                throw new InvalidArgumentException('Only one SQL statement is allowed.');
            }
            if ($char === ';') {
                $ended = true;
                continue;
            }
            if (in_array($char, ["'", '"', '`', '['], true)) {
                $quote = $char === '[' ? ']' : $char;
                $closed = false;
                while (++$i < $length) {
                    if ($sql[$i] !== $quote) {
                        continue;
                    }
                    if ($char !== '[' && $i + 1 < $length && $sql[$i + 1] === $quote) {
                        ++$i;
                        continue;
                    }
                    $closed = true;
                    break;
                }
                if (!$closed) {
                    throw new InvalidArgumentException('SQL has an unterminated quoted value.');
                }
            }
        }
    }
}
