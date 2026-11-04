<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Catalog\Interface\Resource\SongResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class SongResourceTest extends TestCase
{
    public function testSongCarriesItsAlbumTitleAndStoredLanguage(): void
    {
        $song = $this->song();
        $album = $song->getAlbumId()->toString();

        $data = SongResource::fromWithMeta($song, albumTitles: [$album => '直'], albumLanguages: [$album => 'jpn']);

        self::assertSame('直', $data['albumName']);
        self::assertSame('jpn', $data['albumLanguage']);
    }

    public function testAlbumWithoutStoredLanguageSerialisesNull(): void
    {
        $song = $this->song();
        $album = $song->getAlbumId()->toString();

        $data = SongResource::fromWithMeta($song, albumTitles: [$album => 'Album'], albumLanguages: [$album => null]);

        self::assertSame('Album', $data['albumName']);
        self::assertArrayHasKey('albumLanguage', $data);
        self::assertNull($data['albumLanguage']);
    }

    public function testSongWhoseAlbumIsNotVisibleCarriesNoAlbumTitleOrLanguage(): void
    {
        $song = $this->song();
        $otherAlbum = (new Uuid())->toString();

        $data = SongResource::fromWithMeta($song, albumTitles: [$otherAlbum => 'Other'], albumLanguages: [$otherAlbum => 'jpn']);

        self::assertNull($data['albumName']);
        self::assertNull($data['albumLanguage']);
    }

    public function testCollectionCarriesEachSongsAlbumLanguage(): void
    {
        $first = $this->song();
        $second = $this->song();

        $data = SongResource::collectionWithMeta(
            [$first, $second],
            [],
            [$first->getAlbumId()->toString() => 'First', $second->getAlbumId()->toString() => 'Second'],
            albumLanguages: [$first->getAlbumId()->toString() => 'jpn', $second->getAlbumId()->toString() => 'kor'],
        );

        self::assertSame(['jpn', 'kor'], array_column($data, 'albumLanguage'));
    }

    private function song(): Song
    {
        $song = Song::create(new Uuid(), 'Song', '/music/song.flac', 1, 'audio/flac');

        return Song::reconstitute(new SongState(...array_replace(
            get_object_vars($song->getState()), ['albumPublicId' => new PublicId()],
        )));
    }
}
