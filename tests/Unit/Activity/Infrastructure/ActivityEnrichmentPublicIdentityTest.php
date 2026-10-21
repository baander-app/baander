<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Infrastructure;

use App\Activity\Application\Port\ActivityIdentityProjectionPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Song;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class ActivityEnrichmentPublicIdentityTest extends TestCase
{
    /** @return iterable<string, array{?bool}> */
    public static function observations(): iterable
    {
        yield 'available source' => [true];
        yield 'unavailable source' => [false];
        yield 'unindexed source' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('observations')]
    public function testEveryRelationshipUsesItsResolvedPublicIdentity(?bool $observed): void
    {
        $userId = new Uuid();
        $userPublicId = new PublicId();
        $artistId = new Uuid();
        $artistPublicId = new PublicId();
        $album = Album::create(new Uuid(), 'Album', 'studio');
        $song = Song::create($album->getId(), 'Song', '/media/song.mp3', 100, 'audio/mpeg');
        $movie = Movie::create(new Uuid(), 'Movie');
        $activity = MediaActivity::create($userId, 'play', $song->getId(), $album->getId(), $artistId, $movie->getId());
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects($this->once())->method('findByUuids')->with([$song->getId()])->willReturn([$song->getId()->toString() => $song]);
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects($this->once())->method('findByUuids')->with([$album->getId()])->willReturn([$album->getId()->toString() => $album]);
        $albums->method('getArtistNamesForAlbums')->willReturn([$album->getId()->toString() => [['name' => 'Artist', 'role' => null]]]);
        $movies = $this->createMock(MoviePortInterface::class);
        $movies->expects($this->once())->method('findByUuid')->with($movie->getId())->willReturn($movie);
        $identities = $this->createMock(ActivityIdentityProjectionPortInterface::class);
        $identities->expects($this->once())->method('userPublicId')->with($userId)->willReturn($userPublicId);
        $identities->expects($this->once())->method('artistPublicId')->with($artistId)->willReturn($artistPublicId);
        $availability = $this->createMock(\App\Catalog\Application\Port\SongAvailabilityPortInterface::class);
        $availability->expects(self::once())->method('forSongs')->with([$song])->willReturn($observed === null ? [] : [$song->getId()->toString() => $observed]);
        $scopes = $this->createStub(\App\Library\Application\Port\LibraryReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn(\App\Shared\Domain\ValueObject\LibraryReadScope::unrestricted());
        $service = new ActivityEnrichmentService($songs, $albums, $this->createStub(ImagePortInterface::class), $movies, $identities, $availability, $scopes);

        [$output] = $service->enrich([$activity], 'https://baander.app');

        self::assertSame($userPublicId->toString(), $output['userId']);
        self::assertSame($song->getPublicId()->toString(), $output['songId']);
        self::assertSame($album->getPublicId()->toString(), $output['albumId']);
        self::assertSame($artistPublicId->toString(), $output['artistId']);
        self::assertSame($movie->getPublicId()->toString(), $output['movieId']);
        self::assertSame('Song', $output['songTitle']);
        self::assertSame($observed ?? false, $output['songAvailable']);
        self::assertSame('Movie', $output['movieTitle']);
        self::assertNull($output['coverImage']);
        $json = json_encode($output, JSON_THROW_ON_ERROR);
        foreach ([$activity->getId(), $userId, $song->getId(), $album->getId(), $artistId, $movie->getId()] as $id) {
            self::assertStringNotContainsString($id->toString(), $json);
        }
    }
    public function testHistoricalSongsOutsideCurrentLibraryScopeDoNotReachAvailabilityLookup(): void
    {
        $allowedLibrary = new Uuid();
        $allowedAlbum = Album::create($allowedLibrary, 'Allowed album', 'studio');
        $revokedAlbum = Album::create(new Uuid(), 'Revoked album', 'studio');
        $allowedSong = Song::create($allowedAlbum->getId(), 'Allowed song', '/media/allowed.flac', 1, 'audio/flac');
        $revokedSong = Song::create($revokedAlbum->getId(), 'Historical song', '/media/revoked.flac', 1, 'audio/flac');
        $userId = new Uuid();
        $activities = [
            MediaActivity::create($userId, 'play', $allowedSong->getId(), $allowedAlbum->getId()),
            MediaActivity::create($userId, 'play', $revokedSong->getId(), $revokedAlbum->getId()),
        ];
        $songs = $this->createMock(SongPortInterface::class);
        $songs->expects(self::once())->method('findByUuids')->willReturn([
            $allowedSong->getId()->toString() => $allowedSong,
            $revokedSong->getId()->toString() => $revokedSong,
        ]);
        $albums = $this->createMock(AlbumPortInterface::class);
        $albums->expects(self::once())->method('findByUuids')->willReturn([
            $allowedAlbum->getId()->toString() => $allowedAlbum,
            $revokedAlbum->getId()->toString() => $revokedAlbum,
        ]);
        $identities = $this->createStub(ActivityIdentityProjectionPortInterface::class);
        $identities->method('userPublicId')->willReturn(new PublicId());
        $scopes = $this->createMock(\App\Library\Application\Port\LibraryReadScopeProviderInterface::class);
        $scopes->expects(self::once())->method('current')->willReturn(\App\Shared\Domain\ValueObject\LibraryReadScope::restricted([$allowedLibrary]));
        $availability = $this->createMock(\App\Catalog\Application\Port\SongAvailabilityPortInterface::class);
        $availability->expects(self::once())->method('forSongs')->with([$allowedSong])->willReturn([
            $allowedSong->getId()->toString() => true,
        ]);
        $service = new ActivityEnrichmentService($songs, $albums, $this->createStub(ImagePortInterface::class), $this->createStub(MoviePortInterface::class), $identities, $availability, $scopes);

        $output = $service->enrich($activities, 'https://baander.app');

        self::assertTrue($output[0]['songAvailable']);
        self::assertFalse($output[1]['songAvailable']);
        self::assertSame($revokedSong->getPublicId()->toString(), $output[1]['songPublicId']);
        self::assertSame('Historical song', $output[1]['songTitle']);
    }
}
