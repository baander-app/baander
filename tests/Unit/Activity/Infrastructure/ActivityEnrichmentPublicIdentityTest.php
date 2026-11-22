<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity\Infrastructure;

use App\Activity\Application\Port\ActivityIdentityProjectionPortInterface;
use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Infrastructure\ActivityEnrichmentService;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\MoviePortInterface;
use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\Song;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Media\Application\Port\ImagePortInterface;
use App\Media\Domain\Model\Image;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Enriched activities name their items by public identity, and only items inside the
 * viewer's current library access: an activity whose item lies outside it keeps its own
 * fields but carries no title, cover, poster or identifier of that item.
 */
final class ActivityEnrichmentPublicIdentityTest extends TestCase
{
    /** @return iterable<string, array{?bool}> */
    public static function observations(): iterable
    {
        yield 'available source' => [true];
        yield 'unavailable source' => [false];
        yield 'unindexed source' => [null];
    }

    #[DataProvider('observations')]
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
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByUuids')->willReturn([$song->getId()->toString() => $song]);
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuids')->willReturn([$album->getId()->toString() => $album]);
        $albums->method('getArtistNamesForAlbums')->willReturn([$album->getId()->toString() => [['name' => 'Artist', 'role' => null]]]);
        $movies = $this->createStub(MoviePortInterface::class);
        $movies->method('findByUuid')->willReturn($movie);
        $identities = $this->createStub(ActivityIdentityProjectionPortInterface::class);
        $identities->method('userPublicId')->willReturn($userPublicId);
        $identities->method('artistPublicId')->willReturn($artistPublicId);
        $availability = $this->createStub(SongAvailabilityPortInterface::class);
        $availability->method('forSongs')->willReturn($observed === null ? [] : [$song->getId()->toString() => $observed]);
        $service = $this->service(
            scope: LibraryReadScope::unrestricted(),
            songs: $songs,
            albums: $albums,
            movies: $movies,
            identities: $identities,
            availability: $availability,
        );

        [$output] = $service->enrich([$activity], 'https://baander.app');

        self::assertSame($userPublicId->toString(), $output['userId']);
        self::assertSame($song->getPublicId()->toString(), $output['songId']);
        self::assertSame($album->getPublicId()->toString(), $output['albumId']);
        self::assertSame($artistPublicId->toString(), $output['artistId']);
        self::assertSame($movie->getPublicId()->toString(), $output['movieId']);
        self::assertSame('Song', $output['songTitle']);
        self::assertSame('Artist', $output['artistName']);
        self::assertSame($observed ?? false, $output['songAvailable']);
        self::assertSame('Movie', $output['movieTitle']);
        self::assertNull($output['coverImage']);
        $json = json_encode($output, JSON_THROW_ON_ERROR);
        foreach ([$activity->getId(), $userId, $song->getId(), $album->getId(), $artistId, $movie->getId()] as $id) {
            self::assertStringNotContainsString($id->toString(), $json);
        }
    }

    public function testASongOutsideTheCurrentLibraryScopeLeavesItsActivityWithoutItemFields(): void
    {
        $grantedLibrary = new Uuid();
        $grantedCover = $this->image();
        $grantedAlbum = Album::create($grantedLibrary, 'Granted album', 'studio');
        $grantedAlbum->setCoverImage($grantedCover->getId());
        $grantedSong = Song::create($grantedAlbum->getId(), 'Granted song', '/media/granted.flac', 1, 'audio/flac');
        $revokedCover = $this->image();
        $revokedAlbum = Album::create(new Uuid(), 'Revoked album', 'studio');
        $revokedAlbum->setCoverImage($revokedCover->getId());
        $revokedSong = Song::create($revokedAlbum->getId(), 'Revoked song', '/media/revoked.flac', 1, 'audio/flac');
        $grantedArtist = new Uuid();
        $revokedArtist = new Uuid();
        $artistPublicIds = [
            $grantedArtist->toString() => new PublicId(),
            $revokedArtist->toString() => new PublicId(),
        ];
        $userId = new Uuid();
        $granted = MediaActivity::create($userId, 'play', $grantedSong->getId(), $grantedAlbum->getId(), $grantedArtist);
        $revoked = MediaActivity::create($userId, 'play', $revokedSong->getId(), $revokedAlbum->getId(), $revokedArtist);

        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByUuids')->willReturn([
            $grantedSong->getId()->toString() => $grantedSong,
            $revokedSong->getId()->toString() => $revokedSong,
        ]);
        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuids')->willReturn([
            $grantedAlbum->getId()->toString() => $grantedAlbum,
            $revokedAlbum->getId()->toString() => $revokedAlbum,
        ]);
        $albums->method('getArtistNamesForAlbums')->willReturn([
            $grantedAlbum->getId()->toString() => [['name' => 'Granted artist', 'role' => null]],
            $revokedAlbum->getId()->toString() => [['name' => 'Revoked artist', 'role' => null]],
        ]);
        $images = $this->createStub(ImagePortInterface::class);
        $images->method('findByUuids')->willReturn([
            $grantedCover->getId()->toString() => $grantedCover,
            $revokedCover->getId()->toString() => $revokedCover,
        ]);
        $identities = $this->createStub(ActivityIdentityProjectionPortInterface::class);
        $identities->method('userPublicId')->willReturn(new PublicId());
        $identities->method('artistPublicId')->willReturnCallback(
            static fn (Uuid $artistId): PublicId => $artistPublicIds[$artistId->toString()],
        );
        $availability = $this->createStub(SongAvailabilityPortInterface::class);
        $availability->method('forSongs')->willReturn([
            $grantedSong->getId()->toString() => true,
            $revokedSong->getId()->toString() => true,
        ]);
        $service = $this->service(
            scope: LibraryReadScope::restricted([$grantedLibrary]),
            songs: $songs,
            albums: $albums,
            images: $images,
            identities: $identities,
            availability: $availability,
        );

        $output = $service->enrich([$granted, $revoked], 'https://baander.app');

        self::assertSame(
            [$granted->getPublicId()->toString(), $revoked->getPublicId()->toString()],
            array_column($output, 'publicId'),
        );
        [$grantedRow, $revokedRow] = $output;
        self::assertSame($grantedSong->getPublicId()->toString(), $grantedRow['songId']);
        self::assertSame($grantedAlbum->getPublicId()->toString(), $grantedRow['albumId']);
        self::assertSame($artistPublicIds[$grantedArtist->toString()]->toString(), $grantedRow['artistId']);
        self::assertSame('Granted song', $grantedRow['songTitle']);
        self::assertSame('Granted album', $grantedRow['albumTitle']);
        self::assertSame('Granted artist', $grantedRow['artistName']);
        self::assertSame(
            'https://baander.app/api/images/' . $grantedCover->getPublicId()->toString() . '/file',
            $grantedRow['coverImage']['url'],
        );
        self::assertTrue($grantedRow['songAvailable']);

        self::assertSame('play', $revokedRow['activityType']);
        self::assertSame(0, $revokedRow['playCount']);
        foreach (['songId', 'albumId', 'artistId', 'songTitle', 'songPublicId', 'albumTitle', 'albumPublicId', 'coverImage', 'artistName'] as $field) {
            self::assertArrayHasKey($field, $revokedRow);
            self::assertNull($revokedRow[$field], $field);
        }
        self::assertFalse($revokedRow['songAvailable']);
        $json = json_encode($revokedRow, JSON_THROW_ON_ERROR);
        foreach ([
            'Revoked song',
            'Revoked album',
            'Revoked artist',
            $revokedSong->getPublicId()->toString(),
            $revokedAlbum->getPublicId()->toString(),
            $revokedCover->getPublicId()->toString(),
            $artistPublicIds[$revokedArtist->toString()]->toString(),
        ] as $hidden) {
            self::assertStringNotContainsString($hidden, $json);
        }
    }

    public function testAMovieOutsideTheCurrentLibraryScopeLeavesItsActivityWithoutMovieFields(): void
    {
        $grantedLibrary = new Uuid();
        $grantedMovie = Movie::create($grantedLibrary, 'Granted movie');
        $grantedMovie->updateMetadata(posterUrl: 'https://images.baander.app/granted-poster.jpg');
        $revokedMovie = Movie::create(new Uuid(), 'Revoked movie');
        $revokedMovie->updateMetadata(posterUrl: 'https://images.baander.app/revoked-poster.jpg');
        $moviesById = [
            $grantedMovie->getId()->toString() => $grantedMovie,
            $revokedMovie->getId()->toString() => $revokedMovie,
        ];
        $userId = new Uuid();
        $granted = MediaActivity::create($userId, 'play', movieId: $grantedMovie->getId());
        $revoked = MediaActivity::create($userId, 'play', movieId: $revokedMovie->getId());

        $movies = $this->createStub(MoviePortInterface::class);
        $movies->method('findByUuid')->willReturnCallback(
            static fn (Uuid $movieId): ?Movie => $moviesById[$movieId->toString()] ?? null,
        );
        $identities = $this->createStub(ActivityIdentityProjectionPortInterface::class);
        $identities->method('userPublicId')->willReturn(new PublicId());
        $service = $this->service(
            scope: LibraryReadScope::restricted([$grantedLibrary]),
            movies: $movies,
            identities: $identities,
        );

        [$grantedRow, $revokedRow] = $service->enrich([$granted, $revoked], 'https://baander.app');

        self::assertSame($grantedMovie->getPublicId()->toString(), $grantedRow['movieId']);
        self::assertSame($grantedMovie->getPublicId()->toString(), $grantedRow['moviePublicId']);
        self::assertSame('Granted movie', $grantedRow['movieTitle']);
        self::assertSame(['url' => 'https://images.baander.app/granted-poster.jpg'], $grantedRow['posterImage']);

        self::assertSame($revoked->getPublicId()->toString(), $revokedRow['publicId']);
        foreach (['movieId', 'moviePublicId', 'movieTitle', 'posterImage'] as $field) {
            self::assertNull($revokedRow[$field] ?? null, $field);
        }
        $json = json_encode($revokedRow, JSON_THROW_ON_ERROR);
        foreach (['Revoked movie', 'revoked-poster.jpg', $revokedMovie->getPublicId()->toString()] as $hidden) {
            self::assertStringNotContainsString($hidden, $json);
        }
    }

    private function service(
        LibraryReadScope $scope,
        ?SongPortInterface $songs = null,
        ?AlbumPortInterface $albums = null,
        ?ImagePortInterface $images = null,
        ?MoviePortInterface $movies = null,
        ?ActivityIdentityProjectionPortInterface $identities = null,
        ?SongAvailabilityPortInterface $availability = null,
    ): ActivityEnrichmentService {
        $scopes = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn($scope);

        return new ActivityEnrichmentService(
            $songs ?? $this->createStub(SongPortInterface::class),
            $albums ?? $this->createStub(AlbumPortInterface::class),
            $images ?? $this->createStub(ImagePortInterface::class),
            $movies ?? $this->createStub(MoviePortInterface::class),
            $identities ?? $this->createStub(ActivityIdentityProjectionPortInterface::class),
            $availability ?? $this->createStub(SongAvailabilityPortInterface::class),
            $scopes,
        );
    }

    private function image(): Image
    {
        return Image::create('/covers/cover.jpg', 'jpg', 'image/jpeg', 1, 1, 1, 'album');
    }
}
