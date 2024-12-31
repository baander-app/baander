<?php

namespace App\Modules\Translation;

class LocaleString
{
    public const string LOCALE_STRING_DELIMITER = '$_︸_$';

    public static function delimitString(string $value): string
    {
        return self::applyDelimiter($value);
    }

    public static function removeDelimiters(string $value): string
    {
        return self::stripDelimiter($value);
    }

    public static function isLocaleString(string $value): bool
    {
        return str_contains($value, self::LOCALE_STRING_DELIMITER);
    }

    private static function applyDelimiter(string $value): string
    {
        return self::LOCALE_STRING_DELIMITER . $value . self::LOCALE_STRING_DELIMITER;
    }

    private static function stripDelimiter(string $value): string
    {
        return str_replace(self::LOCALE_STRING_DELIMITER, '', $value);
    }
}