<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\Service\AlbumDuplicateDetector;
use App\Catalog\Domain\Service\TitleNormalizer;
use App\Catalog\Infrastructure\AlbumDuplicateService;
use App\Media\Application\Port\ImagePortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use PHPUnit\Framework\TestCase;

final class AlbumDuplicateServiceTest extends TestCase
{
    public function testDuplicatesOfAnAlbumAreAListOfGroupsWithTheAlbumsData(): void
    {
        $library = Uuid::v7();
        $otherA = Album::create($library, 'Giant Steps', 'album', year: 1960);
        $otherB = Album::create($library, 'Giant Steps', 'album', year: 1960);
        $viewed = Album::create($library, 'Blue Train', 'album', year: 1957);
        $duplicate = Album::create($library, 'Blue Train!', 'album', year: 1957);
        // The viewed album's group comes second, so filtering alone would leave key 1.
        $albums = [$otherA, $otherB, $viewed, $duplicate];
        $artists = [];
        foreach ($albums as $album) {
            $artists[$album->getId()->toString()] = [['name' => 'John Coltrane', 'role' => null]];
        }

        $repository = $this->createStub(AlbumRepositoryInterface::class);
        $repository->method('findByLibrary')->willReturn($albums);
        $repository->method('getArtistNamesForAlbums')->willReturn($artists);

        $port = $this->createStub(AlbumPortInterface::class);
        $port->method('findByUuid')->willReturnCallback(static function (Uuid $id) use ($albums): ?Album {
            foreach ($albums as $album) {
                if ($album->getId()->equals($id)) {
                    return $album;
                }
            }

            return null;
        });
        $port->method('findVisibleByUuid')->willReturnCallback(
            static fn (Uuid $id): ?Album => $id->equals($viewed->getId()) ? $viewed : null,
        );
        $port->method('getArtistNamesForAlbums')->willReturn($artists);

        $service = new AlbumDuplicateService(
            new AlbumDuplicateDetector($repository, new TitleNormalizer()),
            $port,
            $this->createStub(ImagePortInterface::class),
        );

        $groups = $service->findVisibleDuplicatesForAlbum($viewed->getId(), LibraryReadScope::unrestricted());

        self::assertTrue(array_is_list($groups));
        self::assertCount(1, $groups);
        self::assertSame(
            [
                [$viewed->getPublicId()->toString(), 'Blue Train', [['name' => 'John Coltrane', 'role' => null]]],
                [$duplicate->getPublicId()->toString(), 'Blue Train!', [['name' => 'John Coltrane', 'role' => null]]],
            ],
            array_map(
                static fn (array $album): array => [$album['publicId'], $album['title'], $album['artists']],
                $groups[0]->getAlbums(),
            ),
        );
    }
}
