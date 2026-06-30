<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Domain\ValueObject;

use App\Library\Domain\ValueObject\LibraryType;
use PHPUnit\Framework\TestCase;

final class LibraryTypeTest extends TestCase
{
    public function testMusicCase(): void
    {
        $type = LibraryType::Music;

        $this->assertSame('music', $type->value);
    }

    public function testPodcastCase(): void
    {
        $type = LibraryType::Podcast;

        $this->assertSame('podcast', $type->value);
    }

    public function testAudiobookCase(): void
    {
        $type = LibraryType::Audiobook;

        $this->assertSame('audiobook', $type->value);
    }

    public function testMovieCase(): void
    {
        $type = LibraryType::Movie;

        $this->assertSame('movie', $type->value);
    }

    public function testTvShowCase(): void
    {
        $type = LibraryType::TvShow;

        $this->assertSame('tv_show', $type->value);
    }

    public function testAllCasesAreBackedEnums(): void
    {
        foreach (LibraryType::cases() as $case) {
            $this->assertIsString($case->value);
            $this->assertNotEmpty($case->value);
        }
    }

    public function testAllCasesCount(): void
    {
        $this->assertCount(5, LibraryType::cases());
    }

    public function testFromValueReturnsCorrectCase(): void
    {
        $this->assertSame(LibraryType::Music, LibraryType::from('music'));
        $this->assertSame(LibraryType::Podcast, LibraryType::from('podcast'));
        $this->assertSame(LibraryType::Audiobook, LibraryType::from('audiobook'));
        $this->assertSame(LibraryType::Movie, LibraryType::from('movie'));
        $this->assertSame(LibraryType::TvShow, LibraryType::from('tv_show'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(LibraryType::tryFrom('invalid'));
    }

    public function testFromThrowsOnInvalidValue(): void
    {
        $this->expectException(\ValueError::class);

        LibraryType::from('nonexistent');
    }

    public function testTvShowUsesUnderscoreInValue(): void
    {
        $this->assertStringContainsString('_', LibraryType::TvShow->value);
    }
}
