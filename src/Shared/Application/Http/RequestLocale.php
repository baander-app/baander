<?php

declare(strict_types=1);

namespace App\Shared\Application\Http;

use App\Shared\Domain\Model\Setting\SupportedLanguages;

/**
 * The language of a request's API messages. ApiLanguageListener (Auth) stores it in the
 * request attribute ATTRIBUTE, and translation sites pass it to the translator explicitly.
 *
 * It never goes through Request::setLocale(): Symfony copies a request's locale onto the
 * worker's one translator, which requests running as coroutines share.
 */
final class RequestLocale
{
    public const string ATTRIBUTE = '_baander_locale';

    /** The language stored in ATTRIBUTE, or English when the request has none. */
    public static function of(mixed $attribute): string
    {
        return is_string($attribute) ? $attribute : SupportedLanguages::FALLBACK;
    }
}
