<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\String\UnicodeString;

/** Stored or untrusted text prepared for an operator's terminal. */
final class TerminalText
{
    /** The text without CSI and OSC sequences, complete or unterminated, and without C0 and C1 controls other than tab and newline. */
    public static function clean(string $text): string
    {
        // Remove complete and unterminated terminal escapes, including OSC clipboard writes.
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\|$)|\x1b\[[0-?]*[ -\/]*[@-~]?|\x1b./s', '', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0b-\x1f\x7f\x{0080}-\x{009f}]/u', '', $text) ?? '';

        return $text;
    }

    /**
     * The cleaned text with its formatter tags escaped, for output the console formatter
     * renders, such as a table cell: a stored `<href=…>` would otherwise become an OSC 8 hyperlink.
     */
    public static function literal(string $text): string
    {
        return OutputFormatter::escape(self::clean($text));
    }

    /**
     * Table rows with every string cell made literal; other cells and separators are kept.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, array<array-key, mixed>|TableSeparator> $rows
     *
     * @return array<TKey, array<array-key, mixed>|TableSeparator>
     */
    public static function literalRows(array $rows): array
    {
        return array_map(self::literalRow(...), $rows);
    }

    /** The cleaned text on one line, cut to at most $width display cells without splitting a grapheme. */
    public static function clip(string $text, int $width): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', self::clean($text));
        $result = '';
        $used = 0;
        preg_match_all('/\X/u', $text, $graphemes);
        foreach ($graphemes[0] as $grapheme) {
            $cells = new UnicodeString($grapheme)->width(false);
            if ($used + $cells > max(0, $width)) {
                break;
            }
            $result .= $grapheme;
            $used += $cells;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed>|TableSeparator $row
     *
     * @return array<array-key, mixed>|TableSeparator
     */
    private static function literalRow(array|TableSeparator $row): array|TableSeparator
    {
        return $row instanceof TableSeparator ? $row : array_map(self::literalCell(...), $row);
    }

    private static function literalCell(mixed $cell): mixed
    {
        return is_string($cell) ? self::literal($cell) : $cell;
    }
}
