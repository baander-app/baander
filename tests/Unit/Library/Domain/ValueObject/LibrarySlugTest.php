<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Domain\ValueObject;

use App\Library\Domain\ValueObject\LibrarySlug;
use PHPUnit\Framework\TestCase;

final class LibrarySlugTest extends TestCase
{
    public function testValidSimpleSlug(): void
    {
        $slug = new LibrarySlug('music');

        $this->assertSame('music', $slug->toString());
    }

    public function testValidSlugWithHyphens(): void
    {
        $slug = new LibrarySlug('my-music-library');

        $this->assertSame('my-music-library', $slug->toString());
    }

    public function testValidSlugWithNumbers(): void
    {
        $slug = new LibrarySlug('library-2024');

        $this->assertSame('library-2024', $slug->toString());
    }

    public function testToStringMagicMethod(): void
    {
        $slug = new LibrarySlug('audiobooks');

        $this->assertSame('audiobooks', (string) $slug);
    }

    public function testSlugIsNormalizedToLowercase(): void
    {
        $slug = new LibrarySlug('My-Library');

        $this->assertSame('my-library', $slug->toString());
    }

    public function testSlugIsTrimmed(): void
    {
        $slug = new LibrarySlug('  music  ');

        $this->assertSame('music', $slug->toString());
    }

    public function testFromNameSimple(): void
    {
        $slug = LibrarySlug::fromName('My Music Library');

        $this->assertSame('my-music-library', $slug->toString());
    }

    public function testFromNameWithSpecialCharacters(): void
    {
        $slug = LibrarySlug::fromName("Alice's Podcast & Talks!");

        $this->assertSame('alice-s-podcast-talks', $slug->toString());
    }

    public function testFromNameAlreadyLowercase(): void
    {
        $slug = LibrarySlug::fromName('music');

        $this->assertSame('music', $slug->toString());
    }

    public function testFromNameWithNumbers(): void
    {
        $slug = LibrarySlug::fromName('Top 100 Hits 2024');

        $this->assertSame('top-100-hits-2024', $slug->toString());
    }

    public function testFromNameWithMultipleSpaces(): void
    {
        $slug = LibrarySlug::fromName('My   Cool   Library');

        $this->assertSame('my-cool-library', $slug->toString());
    }

    public function testFromNameLeadingTrailingSpaces(): void
    {
        $slug = LibrarySlug::fromName('  Music  ');

        $this->assertSame('music', $slug->toString());
    }

    public function testFromNameWithUnderscores(): void
    {
        $slug = LibrarySlug::fromName('my_library_name');

        $this->assertSame('my-library-name', $slug->toString());
    }

    public function testFromNameWithMixedCaseAndSymbols(): void
    {
        $slug = LibrarySlug::fromName('TV Shows & Movies (2024)');

        $this->assertSame('tv-shows-movies-2024', $slug->toString());
    }

    public function testThrowsOnEmptySlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library slug cannot be empty.');

        new LibrarySlug('');
    }

    public function testThrowsOnWhitespaceOnlySlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library slug cannot be empty.');

        new LibrarySlug('   ');
    }

    public function testConstructorDoesNotTransformHyphens(): void
    {
        // The constructor normalizes to lowercase and trims, but does NOT
        // convert spaces to hyphens -- that is what fromName() does.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only lowercase letters, numbers, and hyphens');

        // 'my library' lowercases to 'my library' which has spaces -> invalid
        new LibrarySlug('my library');
    }

    public function testThrowsOnSpacesInConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only lowercase letters, numbers, and hyphens');

        new LibrarySlug('my library');
    }

    public function testThrowsOnUnderscoresInConstructor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only lowercase letters, numbers, and hyphens');

        new LibrarySlug('my_library');
    }

    public function testThrowsOnLeadingHyphen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only lowercase letters, numbers, and hyphens');

        new LibrarySlug('-music');
    }

    public function testThrowsOnTrailingHyphen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only lowercase letters, numbers, and hyphens');

        new LibrarySlug('music-');
    }

    public function testThrowsOnConsecutiveHyphens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only lowercase letters, numbers, and hyphens');

        new LibrarySlug('music--library');
    }

    public function testThrowsOnSpecialCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LibrarySlug('music@library');
    }

    public function testFromNameThatProducesOnlyNonAlphanumeric(): void
    {
        // A name of only special chars would produce an empty slug after trimming
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library slug cannot be empty.');

        LibrarySlug::fromName('!!!@@@');
    }
}
