<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Infrastructure\Security\AuthenticationFailureMessage;
use App\Shared\Application\Http\AcceptLanguageMatcher;
use App\Shared\Domain\Model\Setting\SupportedLanguages;
use Symfony\Component\Translation\Formatter\MessageFormatter;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** Sign-in failure messages translated from the project's auth translation files. */
final class AuthenticationFailureMessages
{
    public static function create(): AuthenticationFailureMessage
    {
        $translator = new Translator(SupportedLanguages::FALLBACK, new MessageFormatter());
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (SupportedLanguages::codes() as $locale) {
            $projectDirectory = dirname(__DIR__, 3);
            $translator->addResource('yaml', sprintf('%s/translations/auth+intl-icu.%s.yaml', $projectDirectory, $locale), $locale, 'auth+intl-icu');
        }

        return new AuthenticationFailureMessage($translator, new AcceptLanguageMatcher());
    }
}
