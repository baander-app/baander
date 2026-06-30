<?php

declare(strict_types=1);

namespace App\Tests\Unit\Favorites\Domain\ValueObject;

use App\Favorites\Domain\ValueObject\FavoriteType;
use PHPUnit\Framework\TestCase;
use ValueError;

final class FavoriteTypeTest extends TestCase
{
    public function testFromReturnsSong(): void
    {
        $type = FavoriteType::from('song');

        $this->assertSame(FavoriteType::Song, $type);
        $this->assertSame('song', $type->value);
    }

    public function testFromReturnsAlbum(): void
    {
        $type = FavoriteType::from('album');

        $this->assertSame(FavoriteType::Album, $type);
        $this->assertSame('album', $type->value);
    }

    public function testFromReturnsArtist(): void
    {
        $type = FavoriteType::from('artist');

        $this->assertSame(FavoriteType::Artist, $type);
        $this->assertSame('artist', $type->value);
    }

    public function testFromThrowsOnInvalidValue(): void
    {
        $this->expectException(ValueError::class);

        FavoriteType::from('playlist');
    }

    public function testLabelReturnsHumanReadableLabel(): void
    {
        $this->assertSame('Song', FavoriteType::Song->label());
        $this->assertSame('Album', FavoriteType::Album->label());
        $this->assertSame('Artist', FavoriteType::Artist->label());
    }

    public function testAllCasesCoverExpectedValues(): void
    {
        $values = array_map(
            static fn (FavoriteType $type): string => $type->value,
            FavoriteType::cases(),
        );

        $this->assertSame(['song', 'album', 'artist'], $values);
    }
}
