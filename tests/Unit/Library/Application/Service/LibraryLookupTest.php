<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Application\Service;

use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Service\LibraryLookup;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\TestCase;

/** The lookup answers within the scope its caller passes; there is no default scope. */
final class LibraryLookupTest extends TestCase
{
    private Library $music;
    private Library $films;
    private LibraryLookup $lookup;

    protected function setUp(): void
    {
        $this->music = Library::create('Music', new LibrarySlug('music'), new LibraryPath('/media/music'), LibraryType::Music, FilesystemType::Local);
        $this->films = Library::create('Films', new LibrarySlug('films'), new LibraryPath('/media/films'), LibraryType::Movie, FilesystemType::Local);
        $this->lookup = new LibraryLookup($this->repositoryHonouringScope($this->music, $this->films));
    }

    public function testTheUnrestrictedScopeFindsAnyLibraryByUuidOrSlug(): void
    {
        $scope = LibraryReadScope::unrestricted();

        self::assertSame($this->music, $this->lookup->byIdentifier($this->music->getId()->toString(), $scope));
        self::assertSame($this->films, $this->lookup->byIdentifier('films', $scope));
    }

    public function testAMemberScopeFindsOnlyThatMembersLibraries(): void
    {
        $scope = LibraryReadScope::restricted([$this->music->getId()]);

        self::assertSame($this->music, $this->lookup->byIdentifier('music', $scope));
        self::assertSame($this->music, $this->lookup->byIdentifier($this->music->getId()->toString(), $scope));

        foreach (['films', $this->films->getId()->toString()] as $identifier) {
            try {
                $this->lookup->byIdentifier($identifier, $scope);
                self::fail('A library outside the member scope must be not found.');
            } catch (LibraryNotFoundException $exception) {
                self::assertSame(sprintf('Library "%s" not found.', $identifier), $exception->getMessage());
            }
        }
    }

    public function testAnEmptyScopeFindsNothing(): void
    {
        $this->expectException(LibraryNotFoundException::class);

        $this->lookup->byIdentifier('music', LibraryReadScope::none());
    }

    /** A repository whose visible finders answer only within the scope, as the database repository does. */
    private function repositoryHonouringScope(Library ...$libraries): LibraryRepositoryInterface
    {
        $repository = $this->createStub(LibraryRepositoryInterface::class);
        $repository->method('findVisibleByUuid')->willReturnCallback(
            static function (Uuid $uuid, LibraryReadScope $scope) use ($libraries): ?Library {
                foreach ($libraries as $library) {
                    if ($library->getId()->equals($uuid) && $scope->allows($library->getId())) {
                        return $library;
                    }
                }

                return null;
            },
        );
        $repository->method('findVisibleBySlug')->willReturnCallback(
            static function (LibrarySlug $slug, LibraryReadScope $scope) use ($libraries): ?Library {
                foreach ($libraries as $library) {
                    if ($library->getSlug()->toString() === $slug->toString() && $scope->allows($library->getId())) {
                        return $library;
                    }
                }

                return null;
            },
        );

        return $repository;
    }
}
