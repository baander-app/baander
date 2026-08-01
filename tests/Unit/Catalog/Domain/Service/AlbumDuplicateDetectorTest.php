<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Service;

use App\Catalog\Domain\Service\AlbumDuplicateDetector;
use App\Catalog\Domain\Service\TitleNormalizer;
use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class AlbumDuplicateDetectorTest extends TestCase
{
    private TitleNormalizer $titleNormalizer;

    protected function setUp(): void
    {
        $this->titleNormalizer = new TitleNormalizer();
    }

    public function testTitleNormalizerRemovesDiacritics(): void
    {
        $result = $this->titleNormalizer->normalize('Café Noir');
        $this->assertSame('cafe noir', $result);
    }

    public function testTitleNormalizerRemovesPunctuation(): void
    {
        $result = $this->titleNormalizer->normalize('Hello, World!');
        $this->assertSame('hello world', $result);
    }

    public function testTitleNormalizerRemovesDisambiguationSuffix(): void
    {
        $result = $this->titleNormalizer->normalize('Album [Reprise Records,9362-49433-2,EU]');
        $this->assertSame('album', $result);
    }

    public function testTitleNormalizerRemovesExtraWhitespace(): void
    {
        $result = $this->titleNormalizer->normalize('  Hello    World  ');
        $this->assertSame('hello world', $result);
    }

    public function testDuplicateGroupRequiresAtLeastTwoAlbums(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate group must contain at least 2 albums.');

        new DuplicateGroup([Uuid::v7()], 0.9);
    }

    public function testDuplicateGroupCanBeCreated(): void
    {
        $id1 = Uuid::v7();
        $id2 = Uuid::v7();

        $group = new DuplicateGroup([$id1, $id2], 0.85);

        $this->assertSame([$id1, $id2], $group->getAlbumIds());
        $this->assertSame(0.85, $group->getConfidence());
        $this->assertSame(2, $group->getAlbumCount());
    }
}
