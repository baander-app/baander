<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Resource;

use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\MovieState;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Model\SongState;
use App\Catalog\Domain\ValueObject\DuplicateGroup;
use App\Catalog\Interface\Resource\DuplicateGroupResource;
use App\Catalog\Interface\Resource\MovieResource;
use App\Catalog\Interface\Resource\SongResource;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class PublicIdentityProjectionTest extends TestCase
{
    public function testSongProjectsItsPublicAlbumIdentity(): void
    {
        $song = Song::create(new Uuid(), 'Song', '/music/song.flac', 1, 'audio/flac');
        $albumPublicId = new PublicId();
        $song = Song::reconstitute(new SongState(...array_replace(
            get_object_vars($song->getState()), ['albumPublicId' => $albumPublicId],
        )));

        $data = SongResource::from($song);
        self::assertSame($albumPublicId->toString(), $data['albumId']);
        self::assertArrayNotHasKey('uuid', $data);
        self::assertNotContains($song->getId()->toString(), $data);
        self::assertNotContains($song->getAlbumId()->toString(), $data);
    }

    public function testMissingAlbumProjectionCannotFallBackToUuid(): void
    {
        $song = Song::create(new Uuid(), 'Song', '/music/song.flac', 1, 'audio/flac');
        $this->expectException(\LogicException::class);
        SongResource::from($song);
    }

    public function testMovieAndDuplicateReferencesUseRelatedPublicIdentities(): void
    {
        $videoId = new Uuid();
        $videoPublicId = new PublicId();
        $movie = Movie::reconstitute(new MovieState(
            id: new Uuid(), publicId: new PublicId(), libraryId: new Uuid(),
            title: 'Movie', year: null, summary: null,
            videoIds: [$videoId->toString()], videoPublicIds: [$videoPublicId->toString()],
        ));
        $data = MovieResource::from($movie);
        self::assertSame([$videoPublicId->toString()], $data['videoIds']);
        self::assertArrayNotHasKey('uuid', $data);
        self::assertStringNotContainsString($videoId->toString(), json_encode($data, JSON_THROW_ON_ERROR));

        $albumIds = [new Uuid(), new Uuid()];
        $albums = [['publicId' => (new PublicId())->toString()], ['publicId' => (new PublicId())->toString()]];
        $duplicates = DuplicateGroupResource::from(new DuplicateGroup($albumIds, 0.9, $albums));
        self::assertSame(array_column($albums, 'publicId'), $duplicates['albumIds']);
        foreach ($albumIds as $id) {
            self::assertStringNotContainsString($id->toString(), json_encode($duplicates, JSON_THROW_ON_ERROR));
        }
    }
}
