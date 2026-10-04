<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

final class CatalogReadScopeRepositoryTest extends TestCase
{
    private LibraryEntity $allowedLibrary;
    private AlbumEntity $allowedAlbum;
    private AlbumEntity $deniedAlbum;
    private SongEntity $allowedSong;
    private SongEntity $deniedSong;
    private ArtistEntity $sharedArtist;
    private ArtistEntity $deniedArtist;
    private ArtistEntity $orphanArtist;
    private LibraryReadScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowedLibrary = new LibraryEntity('Allowed', 'allowed', '/media/allowed', 'music', 'local');
        $deniedLibrary = new LibraryEntity('Denied', 'denied', '/media/denied', 'music', 'local');
        $this->allowedAlbum = new AlbumEntity(new PublicId(), $this->allowedLibrary, 'Acceptance Allowed', 'album');
        $this->deniedAlbum = new AlbumEntity(new PublicId(), $deniedLibrary, 'Acceptance Denied', 'album');
        $this->allowedSong = new SongEntity(new PublicId(), $this->allowedAlbum, 'Acceptance Allowed', '/media/allowed/song.flac', 1, 'audio/flac');
        $this->deniedSong = new SongEntity(new PublicId(), $this->deniedAlbum, 'Acceptance Denied', '/media/denied/song.flac', 1, 'audio/flac');
        $this->sharedArtist = new ArtistEntity(new PublicId(), 'Acceptance Shared');
        $this->deniedArtist = new ArtistEntity(new PublicId(), 'Acceptance Denied');
        $this->orphanArtist = new ArtistEntity(new PublicId(), 'Acceptance Orphan');
        $genre = new GenreEntity('Hidden Genre', 'hidden-genre');
        foreach ([$this->allowedLibrary, $deniedLibrary, $this->allowedAlbum, $this->deniedAlbum, $this->allowedSong, $this->deniedSong, $this->sharedArtist, $this->deniedArtist, $this->orphanArtist, $genre,
            new ArtistAlbumEntity($this->sharedArtist, $this->allowedAlbum, 'primary'),
            new ArtistAlbumEntity($this->sharedArtist, $this->allowedAlbum, 'featured'),
            new ArtistAlbumEntity($this->sharedArtist, $this->deniedAlbum, 'primary'),
            new ArtistSongEntity($this->sharedArtist, $this->allowedSong, 'primary'),
            new ArtistSongEntity($this->deniedArtist, $this->deniedSong, 'primary'),
            new ArtistSongEntity($this->sharedArtist, $this->deniedSong, 'featured'),
            new GenreSongEntity($genre, $this->deniedSong),
            new MovieEntity(new PublicId(), $this->allowedLibrary, 'Acceptance Allowed'),
            new MovieEntity(new PublicId(), $deniedLibrary, 'Acceptance Denied'),
        ] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->scope = LibraryReadScope::restricted([$this->allowedLibrary->getId()]);
    }

    public function testScopesApplyBeforeListingCountsPaginationAndNativeSearch(): void
    {
        foreach ([ArtistRepositoryInterface::class, AlbumRepositoryInterface::class, SongRepositoryInterface::class, MovieRepositoryInterface::class] as $contract) {
            $repository = static::getContainer()->get($contract);
            $this->assertSame(1, $repository->countVisible($this->scope));
            $this->assertSame(0, $repository->countVisible(LibraryReadScope::none()));
            $result = $repository->searchVisible(SearchOptions::create('Acceptance', limit: 1), $this->scope);
            $this->assertSame(1, $result->getTotal());
            $this->assertCount(1, $result->getItems());
            $this->assertSame(0, $repository->searchVisible(SearchOptions::create('Acceptance'), LibraryReadScope::none())->getTotal());
            $this->assertCount(0, $repository->searchVisible(SearchOptions::create('Acceptance', limit: 1, offset: 1), $this->scope)->getItems());
        }
        $artists = static::getContainer()->get(ArtistRepositoryInterface::class);
        $this->assertSame(1, $artists->searchVisible(SearchOptions::create('', limit: 1), $this->scope)->getTotal());
        $this->assertSame(3, $artists->countVisible(LibraryReadScope::unrestricted()));
        $this->assertNull($artists->findVisibleByPublicId($this->orphanArtist->getPublicId(), $this->scope));
        $this->assertNull($artists->findVisibleByUuid($this->deniedArtist->getId(), $this->scope));
    }

    public function testAlbumArtistFilterCountsAndPaginatesAlbumsOnceAcrossRoles(): void
    {
        $albums = static::getContainer()->get(AlbumRepositoryInterface::class);
        foreach (['', 'Acceptance'] as $query) {
            $options = SearchOptions::create($query, limit: 1)->withFilters([
                [
                    'field' => 'artistId',
                    'operator' => 'eq',
                    'value' => $this->sharedArtist->getPublicId()->toString(),
                ],
            ]);
            $result = $albums->searchVisible($options, $this->scope);
            $this->assertSame(1, $result->getTotal());
            $this->assertCount(1, $result->getItems());
            $this->assertSame($this->allowedAlbum->getId()->toString(), $result->getItems()[0]->getId()->toString());

            $nextOptions = SearchOptions::create($query, limit: 1, offset: 1)
                ->withFilters($options->getFilters());
            $nextPage = $albums->searchVisible($nextOptions, $this->scope);
            $this->assertSame(1, $nextPage->getTotal());
            $this->assertCount(0, $nextPage->getItems());
            $this->assertSame(2, $albums->searchVisible($options, LibraryReadScope::unrestricted())->getTotal());
        }
    }

    public function testRelatedReadsAndCursorDoNotDiscloseDeniedMetadata(): void
    {
        $albums = static::getContainer()->get(AlbumRepositoryInterface::class);
        $songs = static::getContainer()->get(SongRepositoryInterface::class);
        $this->assertNull($albums->findVisibleWithSongs($this->deniedAlbum->getId(), $this->scope));
        $this->assertSame([], $albums->getVisibleArtistNamesForAlbum($this->deniedAlbum->getId(), $this->scope));
        $visible = $albums->findVisibleWithSongs($this->allowedAlbum->getId(), $this->scope);
        $this->assertNotNull($visible);
        $this->assertCount(1, $visible[1]);
        $this->assertSame([$this->allowedSong->getId()->toString() => $this->sharedArtist->getName()], $songs->getVisibleArtistNamesForSongs([$this->allowedSong->getId(), $this->deniedSong->getId()], $this->scope));
        $this->assertSame([$this->allowedAlbum->getId()->toString() => $this->allowedAlbum->getTitle()], $songs->getVisibleAlbumTitlesByIds([$this->allowedAlbum->getId(), $this->deniedAlbum->getId()], $this->scope));
        $page = $songs->searchVisibleWithCursor(SearchOptions::create('Acceptance'), $this->scope);
        $this->assertSame(1, $page->getTotal());
        $this->assertCount(1, $page->getItems());
        $this->assertSame($this->allowedSong->getId()->toString(), $page->getItems()[0]->getId()->toString());
    }

    public function testArtistGenreFilteringCannotMatchGenreFromDeniedLibrary(): void
    {
        $artists = static::getContainer()->get(ArtistRepositoryInterface::class);
        foreach (['', 'Acceptance'] as $query) {
            $options = SearchOptions::create($query)->withFilters([['field' => 'genre', 'operator' => 'eq', 'value' => 'hidden-genre']]);
            $this->assertSame(0, $artists->searchVisible($options, $this->scope)->getTotal());
            $this->assertSame(2, $artists->searchVisible($options, LibraryReadScope::unrestricted())->getTotal());
        }
    }

    public function testNativeScopedQueryCanBeExplainedWithExistingIndexes(): void
    {
        $predicate = CatalogReadScopeQuery::native($this->scope, 'artist', SearchOptions::create('Acceptance'));
        $plan = $this->entityManager->getConnection()->executeQuery(
            'EXPLAIN (FORMAT JSON) SELECT s.id FROM artists s WHERE s.name &@~ :query AND (' . $predicate['predicate'] . ')',
            ['query' => 'Acceptance'] + $predicate['parameters'],
            $predicate['types'],
        )->fetchOne();
        $this->assertIsString($plan);
        $decoded = json_decode($plan, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('Plan', $decoded[0]);
        $this->assertStringContainsString('library_id', $plan);
    }
}
