<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Service;

use App\Catalog\Domain\Service\TitleNormalizer;
use PHPUnit\Framework\TestCase;

final class TitleNormalizerTest extends TestCase
{
    private TitleNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TitleNormalizer();
    }

    public function testNormalizeLowercasesAndStripsDiacritics(): void
    {
        // Requires a working ICU transliterator; proves the transliteration path.
        self::assertSame('cafe', $this->normalizer->normalize('Café'));
    }

    public function testNormalizeStripsDisambiguationBrackets(): void
    {
        self::assertSame(
            'symphony',
            $this->normalizer->normalize('Symphony [Deutsche Grammophon, 419 272-2, DE]'),
        );
    }

    public function testNormalizeCollapsesPunctuationAndWhitespace(): void
    {
        self::assertSame('song name', $this->normalizer->normalize('  Song!!!  Name?  '));
    }
}
