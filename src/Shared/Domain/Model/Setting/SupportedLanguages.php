<?php

declare(strict_types=1);

namespace App\Shared\Domain\Model\Setting;

/**
 * The languages emails can be sent in. A language belongs here only when its
 * authentication and notification translations are complete; the translation
 * parity test enforces that.
 */
final class SupportedLanguages
{
    public const string FALLBACK = 'en';

    /** Native name per language code, in display order. */
    public const array NATIVE_NAMES = [
        'en' => 'English',
        'da' => 'Dansk',
        'th' => 'ไทย',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::NATIVE_NAMES);
    }
}
