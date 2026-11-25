<?php

declare(strict_types=1);

namespace App\Tests\Unit\Metadata\Application\Exception;

use App\Metadata\Application\Exception\UnreadableMediaFileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * A rejected media file keeps an English message for the console and logs, and the HTTP error
 * response translates it from the `metadata` catalog into the request locale.
 */
final class UnreadableMediaFileExceptionTest extends TestCase
{
    /** @return iterable<string, array{UnreadableMediaFileException}> */
    public static function exceptions(): iterable
    {
        yield 'missing file' => [UnreadableMediaFileException::missing()];
        yield 'unsupported format' => [UnreadableMediaFileException::unsupportedFormat()];
    }

    /** @return iterable<string, array{UnreadableMediaFileException, string}> */
    public static function danish(): iterable
    {
        yield 'missing file' => [UnreadableMediaFileException::missing(), 'Gyldig "path" til en eksisterende fil i et bibliotek er påkrævet.'];
        yield 'unsupported format' => [UnreadableMediaFileException::unsupportedFormat(), 'Lydformatet understøttes ikke.'];
    }

    #[DataProvider('exceptions')]
    public function testTheEnglishCatalogSaysWhatTheConsoleSays(UnreadableMediaFileException $exception): void
    {
        self::assertSame($exception->getMessage(), $exception->trans(self::translator(), 'en'));
    }

    #[DataProvider('danish')]
    public function testTheMessageIsTranslatedIntoTheLocale(UnreadableMediaFileException $exception, string $danish): void
    {
        self::assertSame($danish, $exception->trans(self::translator(), 'da'));
    }

    private static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'da', 'th'] as $locale) {
            $translator->addResource('yaml', sprintf('%s/translations/metadata+intl-icu.%s.yaml', dirname(__DIR__, 5), $locale), $locale, 'metadata+intl-icu');
        }

        return $translator;
    }
}
