<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Translation;

use App\Shared\Domain\Model\Setting\SupportedLanguages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A language can be offered for email only when every authentication and
 * notification message has a translation in it.
 */
final class EmailTranslationParityTest extends TestCase
{
    private const array DOMAINS = ['auth', 'notification'];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languagesAndDomains(): iterable
    {
        foreach (SupportedLanguages::codes() as $language) {
            foreach (self::DOMAINS as $domain) {
                yield sprintf('%s %s', $domain, $language) => [$domain, $language];
            }
        }
    }

    #[DataProvider('languagesAndDomains')]
    public function testLanguageHasEveryEnglishKey(string $domain, string $language): void
    {
        $english = self::keys($domain, SupportedLanguages::FALLBACK);
        $translated = self::keys($domain, $language);

        $this->assertSame([], array_values(array_diff($english, $translated)), sprintf('Missing %s translations in %s.', $language, $domain));
        $this->assertSame([], array_values(array_diff($translated, $english)), sprintf('Keys in %s %s that English does not have.', $language, $domain));
    }

    /**
     * @return list<string>
     */
    private static function keys(string $domain, string $language): array
    {
        $file = sprintf('%s/translations/%s+intl-icu.%s.yaml', dirname(__DIR__, 4), $domain, $language);
        self::assertFileExists($file);

        $messages = Yaml::parseFile($file);
        self::assertIsArray($messages);

        return self::flatten($messages);
    }

    /**
     * @param array<array-key, mixed> $messages
     *
     * @return list<string>
     */
    private static function flatten(array $messages, string $prefix = ''): array
    {
        $keys = [];
        foreach ($messages as $key => $value) {
            $path = $prefix . $key;
            $keys = is_array($value) ? [...$keys, ...self::flatten($value, $path . '.')] : [...$keys, $path];
        }

        return $keys;
    }
}
