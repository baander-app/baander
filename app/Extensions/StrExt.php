<?php

namespace App\Extensions;

class StrExt
{
    public static function between($str, $starting_word, $ending_word): ?string
    {
        if (!$str) {
            return null;
        }

        try {
            $subtring_start = strpos($str, $starting_word);
            if ($subtring_start === false || $subtring_start <= 0) {
                return null;
            }

            // Adding the starting index of the starting word to
            // its length would give its ending index
            $subtring_start += strlen($starting_word);
            // Length of our required sub string
            $size = strpos($str, $ending_word, $subtring_start) - $subtring_start;

            if ($size === false || $size <= 0) {
                return null;
            }

            // Return the substring from the index substring_start of length size
            return substr($str, $subtring_start, $size);
        } catch (\Exception) {
            return null;
        }
    }

    public static function safe($str)
    {
        if (!$str) {
            return null;
        }

        return strip_tags(str_replace('\x00', '', $str));
    }

    public static function convertToUtf8($str): string|null
    {
        if (!$str) {
            return null;
        }

        $encoding = mb_detect_encoding($str);

        return mb_convert_encoding($str, 'UTF-8', $encoding);
    }
}
