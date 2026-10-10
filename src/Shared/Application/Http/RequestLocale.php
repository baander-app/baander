<?php

declare(strict_types=1);

namespace App\Shared\Application\Http;

use App\Shared\Domain\Model\Setting\SupportedLanguages;

/**
 * The language of a request's API messages. ApiLanguageListener (Auth) stores a resolver
 * for it in the request attribute ATTRIBUTE, and translation sites pass the result of of()
 * to the translator explicitly.
 *
 * It never goes through Request::setLocale(): Symfony copies a request's locale onto the
 * worker's one translator, which requests running as coroutines share.
 */
final class RequestLocale
{
    public const string ATTRIBUTE = '_baander_locale';

    /**
     * The language ATTRIBUTE holds, or English when the request has none. The attribute is
     * a language code or a closure that resolves one.
     */
    public static function of(mixed $attribute): string
    {
        if ($attribute instanceof \Closure) {
            $attribute = $attribute();
        }

        return is_string($attribute) ? $attribute : SupportedLanguages::FALLBACK;
    }
}
