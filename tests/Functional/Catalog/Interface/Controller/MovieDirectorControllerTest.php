<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog\Interface\Controller;

use App\Tests\Fixtures\Catalog\IndexedMovie;

use App\Catalog\Application\Port\DirectorCredit;
use App\Catalog\Application\Port\DirectorPortInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Tests\Functional\TestCase;

final class MovieDirectorControllerTest extends TestCase
{
    public function testDirectoryAndMovieEndpointsReturnScopedResourcesAndHideDeniedDirectors(): void
    {
        $tag = bin2hex(random_bytes(5));
        $library = new LibraryEntity('Owned', 'director-http-' . $tag, '/media/director-http', 'movie', 'local');
        $movie = new MovieEntity(new PublicId(), $library, 'Owned Director Movie');
        $this->entityManager->persist($library);
        $this->entityManager->persist($movie);
        $this->entityManager->flush();
        IndexedMovie::add($this->entityManager, $movie);
        $this->entityManager->flush();
        $directors = static::getContainer()->get(DirectorPortInterface::class);
        static::getContainer()->get(TransactionPortInterface::class)->run(
            static fn(): bool => $directors->syncMovieDirectors($movie->getId(), [new DirectorCredit(2_000_000_061, 'Owned Director')]),
        );
        $director = $directors->searchVisible(SearchOptions::create(''), LibraryReadScope::unrestricted())->getItems()[0];
        $provider = $this->createStub(LibraryReadScopeProviderInterface::class);
        $scope = LibraryReadScope::restricted([$library->getId()]);
        $provider->method('current')->willReturnCallback(static function () use (&$scope): LibraryReadScope {
            return $scope;
        });
        static::getContainer()->set(LibraryReadScopeProviderInterface::class, $provider);
        $user = $this->createTestUser();
        $response = $this->authenticatedRequest('GET', '/api/movies/directors?page=1&limit=1&q=Owned', $user);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['meta']['total']);
        self::assertSame(1, $data['data'][0]['movieCount']);
        self::assertSame($director->publicId->toString(), $data['data'][0]['publicId']);
        $path = '/api/movies/directors/' . $director->publicId->toString();
        self::assertSame(200, $this->authenticatedRequest('GET', $path, $user)->getStatusCode());
        $movies = $this->authenticatedRequest('GET', $path . '/movies', $user);
        self::assertSame(200, $movies->getStatusCode());
        $data = json_decode($movies->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($movie->getPublicId()->toString(), $data['data'][0]['publicId']);
        $scope = LibraryReadScope::none();
        self::assertSame(404, $this->authenticatedRequest('GET', $path, $user)->getStatusCode());
        self::assertSame(404, $this->authenticatedRequest('GET', $path . '/movies', $user)->getStatusCode());
    }
}
