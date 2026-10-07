<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Interface\Request;

use App\Auth\Interface\Request\AcceptLanguageMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AcceptLanguageMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function headers(): iterable
    {
        yield 'regional tag maps to its language' => ['da-DK,da;q=0.9,en;q=0.5', 'da'];
        yield 'english browser' => ['en-US,en;q=0.9', 'en'];
        yield 'unsupported first choice falls to the next supported one' => ['de-DE,en;q=0.5', 'en'];
        yield 'q=0 excludes a language' => ['th;q=0,da;q=0.4', 'da'];
        yield 'highest q wins regardless of order' => ['en;q=0.3,th;q=0.8', 'th'];
        yield 'equal q keeps header order' => ['th,da', 'th'];
        yield 'case is ignored' => ['DA-dk', 'da'];
        yield 'wildcard alone matches nothing' => ['*', null];
        yield 'only unsupported languages' => ['de,fr;q=0.8', null];
        yield 'empty header' => ['', null];
        yield 'no header' => [null, null];
        yield 'malformed weight is ignored' => ['da;q=abc', null];
    }

    #[DataProvider('headers')]
    public function testPicksTheHighestRankedSupportedLanguage(?string $header, ?string $expected): void
    {
        $this->assertSame($expected, (new AcceptLanguageMatcher())->match($header));
    }
}
