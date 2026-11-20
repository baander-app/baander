<?php

declare(strict_types=1);

namespace App\Shared\Interface\Console\Log;

use Symfony\Component\String\UnicodeString;

final class LogTerminalText
{
    public static function clean(string $text): string
    {
        // Remove complete and unterminated terminal escapes, including OSC clipboard writes.
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\|$)|\x1b\[[0-?]*[ -\/]*[@-~]?|\x1b./s', '', $text) ?? '';
        $text = preg_replace('/[\x00-\x08\x0b-\x1f\x7f\x{0080}-\x{009f}]/u', '', $text) ?? '';

        return $text;
    }

    public static function clip(string $text, int $width): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', self::clean($text));
        $result = '';
        $used = 0;
        preg_match_all('/\X/u', $text, $graphemes);
        foreach ($graphemes[0] as $grapheme) {
            $cells = (new UnicodeString($grapheme))->width(false);
            if ($used + $cells > max(0, $width)) {
                break;
            }
            $result .= $grapheme;
            $used += $cells;
        }

        return $result;
    }
}
