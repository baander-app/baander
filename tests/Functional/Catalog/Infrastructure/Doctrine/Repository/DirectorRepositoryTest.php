<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Infrastructure\Doctrine\Repository;

use App\Tests\Fixtures\Catalog\IndexedMovie;

use App\Catalog\Application\Port\DirectorCredit;
use App\Catalog\Application\Port\DirectorPortInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\PersonEntity;
use App\Catalog\Infrastructure\Doctrine\Repository\MovieRepository;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

final class DirectorRepositoryTest extends TestCase
{
    public function testCreditsPersistIdempotentlyAndAllDirectoryQueriesApplyLibraryScope(): void
    {
        [$allowed, $denied] = $this->movies();
        $directors = static::getContainer()->get(DirectorPortInterface::class);
        $one = new DirectorCredit(2_000_000_041, 'Shared Director', 'https://images.baander.app/director.jpg');
        $two = new DirectorCredit(2_000_000_042, 'Shared Director');
        $hidden = new DirectorCredit(2_000_000_043, 'Hidden Director');
        $this->sync($directors, $allowed->getId(), [$one, $one, $two]);
        $this->sync($directors, $denied->getId(), [$one, $hidden]);
        $this->entityManager->clear();
        $scope = LibraryReadScope::restricted([$allowed->getLibrary()->getId()]);
        $result = $directors->searchVisible(SearchOptions::create('', 1), $scope);
        self::assertSame(2, $result->getTotal());
        self::assertCount(1, $result->getItems());
        $first = $result->getItems()[0];
        self::assertSame(1, $first->movieCount);
        $id = $first->publicId;
        self::assertSame(2, $directors->searchVisible(SearchOptions::create('Shared'), $scope)->getTotal());
        self::assertCount(1, $directors->searchVisible(SearchOptions::create('', 1, 1), $scope)->getItems());
        self::assertSame(0, $directors->searchVisible(SearchOptions::create('Shared'), LibraryReadScope::none())->getTotal());
        self::assertNull($directors->findVisibleByPublicId($id, LibraryReadScope::none()));
        self::assertSame(1, $directors->searchMoviesVisible($id, SearchOptions::create('Owned'), $scope)->getTotal());
        self::assertSame(2, $directors->searchMoviesVisible($id, SearchOptions::create('Owned'), LibraryReadScope::unrestricted())->getTotal());
        self::assertSame(0, $directors->searchMoviesVisible($id, SearchOptions::create(''), LibraryReadScope::none())->getTotal());
        self::assertFalse($this->sync($directors, $allowed->getId(), [], false));
        self::assertTrue($this->sync($directors, $allowed->getId(), [$one, $two], true));
        self::assertTrue($directors->findVisibleByPublicId($id, $scope)->publicId->equals($id));
        self::assertCount(3, $this->entityManager->getRepository(PersonEntity::class)->findAll());
        self::assertTrue($this->sync($directors, $allowed->getId(), [], true));
        self::assertSame(0, $directors->searchVisible(SearchOptions::create(''), $scope)->getTotal());
        self::assertSame(2, $directors->searchVisible(SearchOptions::create(''), LibraryReadScope::unrestricted())->getTotal());
    }

    public function testFilmographyFiltersUnavailableWorksBeforeCountingAndPaging(): void
    {
        [$owned, $hidden] = $this->movies();
        $missing = new MovieEntity(new PublicId(), $owned->getLibrary(), 'A missing movie');
        $last = new MovieEntity(new PublicId(), $owned->getLibrary(), 'Z available movie');
        $this->entityManager->persist($missing);
        $this->entityManager->persist($last);
        IndexedMovie::add($this->entityManager, $missing, available: false);
        IndexedMovie::add($this->entityManager, $last);
        $this->entityManager->flush();
        $directors = static::getContainer()->get(DirectorPortInterface::class);
        $credit = new DirectorCredit(2_000_000_049, 'Availability Director');
        foreach ([$owned, $hidden, $missing, $last] as $movie) {
            $this->sync($directors, $movie->getId(), [$credit]);
        }
        $scope = LibraryReadScope::restricted([$owned->getLibrary()->getId()]);
        $director = $directors->searchVisible(SearchOptions::create('Availability Director'), $scope)->getItems()[0];
        $first = $directors->searchMoviesVisible($director->publicId, SearchOptions::create('', 1), $scope);
        $second = $directors->searchMoviesVisible($director->publicId, SearchOptions::create('', 1, 1), $scope);
        self::assertSame(2, $first->getTotal());
        self::assertSame(2, $second->getTotal());
        self::assertTrue($first->getItems()[0]->getPublicId()->equals($owned->getPublicId()));
        self::assertTrue($second->getItems()[0]->getPublicId()->equals($last->getPublicId()));
        self::assertSame(3, $director->movieCount);
        self::assertSame(3, $directors->searchMoviesVisible($director->publicId, SearchOptions::create(''), LibraryReadScope::unrestricted())->getTotal());
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE library_file_index SET hash = :hash WHERE library_id = :library AND path = :path',
            ['hash' => hash('xxh3', 'changed'), 'library' => $owned->getLibrary()->getId()->toString(), 'path' => rtrim($owned->getLibrary()->getPath(), '/') . '/' . $owned->getId()->toString() . '.mp4'],
        );
        $filtered = $directors->searchMoviesVisible($director->publicId, SearchOptions::create('', 1), $scope);
        self::assertSame(1, $filtered->getTotal());
        self::assertTrue($filtered->getItems()[0]->getPublicId()->equals($last->getPublicId()));
    }

    public function testDeletingACreditedMovieDoesNotLeaveManagedLinksThatBreakLaterWrites(): void
    {
        [$movie] = $this->movies();
        $directors = static::getContainer()->get(DirectorPortInterface::class);
        $this->sync($directors, $movie->getId(), [new DirectorCredit(2_000_000_051, 'Owned Director')]);
        $repository = new MovieRepository($this->entityManager);
        $domain = $repository->findByUuid($movie->getId());
        self::assertNotNull($domain);
        $repository->delete($domain);
        $this->entityManager->flush();
        self::assertSame(0, $directors->searchVisible(SearchOptions::create(''), LibraryReadScope::unrestricted())->getTotal());
    }

    /** @param list<DirectorCredit> $credits */
    private function sync(DirectorPortInterface $directors, Uuid $movieId, array $credits, bool $force = false): bool
    {
        return static::getContainer()->get(TransactionPortInterface::class)->run(
            static fn(): bool => $directors->syncMovieDirectors($movieId, $credits, $force),
        );
    }

    /** @return array{MovieEntity, MovieEntity} */
    private function movies(): array
    {
        $tag = bin2hex(random_bytes(5));
        $allowed = new LibraryEntity('Allowed', 'director-allowed-' . $tag, '/media/director-allowed', 'movie', 'local');
        $denied = new LibraryEntity('Denied', 'director-denied-' . $tag, '/media/director-denied', 'movie', 'local');
        $first = new MovieEntity(new PublicId(), $allowed, 'Owned A');
        $second = new MovieEntity(new PublicId(), $denied, 'Owned B');
        foreach ([$allowed, $denied, $first, $second] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        IndexedMovie::add($this->entityManager, $first);
        IndexedMovie::add($this->entityManager, $second);
        $this->entityManager->flush();

        return [$first, $second];
    }
}
