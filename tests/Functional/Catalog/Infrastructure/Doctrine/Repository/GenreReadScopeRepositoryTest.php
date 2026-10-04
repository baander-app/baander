<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\ReadModel\GenreReadView;
use App\Catalog\Domain\Repository\GenreRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreMovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

final class GenreReadScopeRepositoryTest extends TestCase
{
    /** @var array<string, GenreEntity> */
    private array $genres;
    private LibraryReadScope $scope;
    private GenreRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $allowed = new LibraryEntity('Allowed', 'allowed', '/media/allowed', 'music', 'local');
        $denied = new LibraryEntity('Denied', 'denied', '/media/denied', 'music', 'local');
        $allowedAlbum = new AlbumEntity(new PublicId(), $allowed, 'Allowed Album', 'album');
        $deniedAlbum = new AlbumEntity(new PublicId(), $denied, 'Denied Album', 'album');
        $allowedSong = new SongEntity(new PublicId(), $allowedAlbum, 'Allowed Song', '/media/allowed/song.flac', 1, 'audio/flac');
        $deniedSong = new SongEntity(new PublicId(), $deniedAlbum, 'Denied Song', '/media/denied/song.flac', 1, 'audio/flac');
        $allowedMovie = new MovieEntity(new PublicId(), $allowed, 'Allowed Movie');
        $this->genres = [];
        foreach (['song', 'album', 'movie', 'shared', 'denied', 'orphan', 'hidden-parent', 'visible-parent'] as $slug) {
            $this->genres[$slug] = new GenreEntity($slug, $slug, mbid: 'mbid-' . $slug);
        }
        $this->genres['promoted-child'] = new GenreEntity('promoted-child', 'promoted-child', $this->genres['hidden-parent']);
        $this->genres['visible-child'] = new GenreEntity('visible-child', 'visible-child', $this->genres['visible-parent']);
        $this->genres['hidden-child'] = new GenreEntity('hidden-child', 'hidden-child', $this->genres['visible-parent']);

        $entities = [
            $allowed, $denied, $allowedAlbum, $deniedAlbum, $allowedSong, $deniedSong, $allowedMovie,
            ...array_values($this->genres),
            new GenreSongEntity($this->genres['song'], $allowedSong),
            new GenreAlbumEntity($this->genres['album'], $allowedAlbum),
            new GenreMovieEntity($this->genres['movie'], $allowedMovie),
            new GenreSongEntity($this->genres['shared'], $allowedSong),
            new GenreAlbumEntity($this->genres['shared'], $allowedAlbum),
            new GenreMovieEntity($this->genres['shared'], $allowedMovie),
            new GenreAlbumEntity($this->genres['shared'], $deniedAlbum),
            new GenreSongEntity($this->genres['denied'], $deniedSong),
            new GenreAlbumEntity($this->genres['hidden-parent'], $deniedAlbum),
            new GenreSongEntity($this->genres['promoted-child'], $allowedSong),
            new GenreAlbumEntity($this->genres['visible-parent'], $allowedAlbum),
            new GenreMovieEntity($this->genres['visible-child'], $allowedMovie),
            new GenreSongEntity($this->genres['hidden-child'], $deniedSong),
        ];
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->scope = LibraryReadScope::restricted([$allowed->getId()]);
        $this->repository = static::getContainer()->get(GenreRepositoryInterface::class);
    }

    public function testEveryDirectAssociationContributesVisibilityWithoutDuplicateRows(): void
    {
        $this->assertSame(7, $this->repository->countVisible($this->scope));
        $this->assertSame(
            ['album', 'movie', 'promoted-child', 'shared', 'song', 'visible-child', 'visible-parent'],
            $this->slugs($this->repository->findAllVisible($this->scope)),
        );
        foreach (['song', 'album', 'movie', 'shared'] as $slug) {
            $view = $this->repository->findVisibleBySlug($slug, $this->scope);
            $this->assertNotNull($view);
            $this->assertSame('mbid-' . $slug, $view->getMbid());
            $this->assertSame($slug, $this->repository->findVisibleByUuid($this->genres[$slug]->getId(), $this->scope)?->getSlug());
        }
        foreach (['denied', 'orphan', 'hidden-parent', 'hidden-child'] as $slug) {
            $this->assertNull($this->repository->findVisibleBySlug($slug, $this->scope));
            $this->assertNull($this->repository->findVisibleByUuid($this->genres[$slug]->getId(), $this->scope));
        }
        $this->assertSame(11, $this->repository->countVisible(LibraryReadScope::unrestricted()));
        $this->assertCount(11, $this->repository->findAllVisible(LibraryReadScope::unrestricted()));
        $this->assertNotNull($this->repository->findVisibleBySlug('orphan', LibraryReadScope::unrestricted()));
    }

    public function testHierarchyUsesVisibleParentsAndPromotesChildrenOfHiddenParents(): void
    {
        $this->assertSame(
            ['album', 'movie', 'promoted-child', 'shared', 'song', 'visible-parent'],
            $this->slugs($this->repository->findVisibleRootGenres($this->scope)),
        );
        $this->assertSame(
            ['visible-child'],
            $this->slugs($this->repository->findVisibleChildren($this->genres['visible-parent']->getId(), $this->scope)),
        );
        $this->assertSame([], $this->repository->findVisibleChildren($this->genres['hidden-parent']->getId(), $this->scope));
        $this->assertNull($this->repository->findVisibleBySlug('promoted-child', $this->scope)?->getParent());
        $this->assertSame(
            $this->genres['visible-parent']->getId()->toString(),
            $this->repository->findVisibleBySlug('visible-child', $this->scope)?->getParent()?->toString(),
        );
        $this->assertCount(8, $this->repository->findVisibleRootGenres(LibraryReadScope::unrestricted()));
        $this->assertCount(2, $this->repository->findVisibleChildren(
            $this->genres['visible-parent']->getId(),
            LibraryReadScope::unrestricted(),
        ));
        $this->assertSame(
            $this->genres['hidden-parent']->getId()->toString(),
            $this->repository->findVisibleBySlug('promoted-child', LibraryReadScope::unrestricted())?->getParent()?->toString(),
        );
    }

    public function testEmptyScopeDeniesEveryRead(): void
    {
        $scope = LibraryReadScope::none();
        $this->assertSame(0, $this->repository->countVisible($scope));
        $this->assertSame([], $this->repository->findAllVisible($scope));
        $this->assertSame([], $this->repository->findVisibleRootGenres($scope));
        $this->assertSame([], $this->repository->findVisibleChildren($this->genres['visible-parent']->getId(), $scope));
        $this->assertNull($this->repository->findVisibleBySlug('song', $scope));
        $this->assertNull($this->repository->findVisibleByUuid($this->genres['song']->getId(), $scope));
    }

    public function testScopedProjectionNeverChangesPersistedParent(): void
    {
        $childId = $this->genres['promoted-child']->getId();
        $parentId = $this->genres['hidden-parent']->getId();
        $view = $this->repository->findVisibleByUuid($childId, $this->scope);
        $this->assertNotNull($view);
        $this->assertNull($view->getParent());
        $this->assertSame($parentId->toString(), $this->genres['promoted-child']->getParent()?->getId()->toString());
        $this->entityManager->flush();
        $this->entityManager->clear();
        $stored = $this->entityManager->find(GenreEntity::class, $childId);
        $this->assertNotNull($stored);
        $this->assertSame($parentId->toString(), $stored->getParent()?->getId()->toString());
    }

    /**
     * @param GenreReadView[] $views
     * @return string[]
     */
    private function slugs(array $views): array
    {
        return array_map(static fn (GenreReadView $view): string => $view->getSlug(), $views);
    }
}
