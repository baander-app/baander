<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Infrastructure\AlbumMergeService;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class AlbumMergeServiceTest extends TestCase
{
    public function testMergingIntoAnAlbumWithALockedEmptyFieldLeavesItEmptyAndMergesTheRest(): void
    {
        $library = new Uuid();
        $target = Album::create($library, 'Abbey Road', 'album');
        $target->lockField('label');
        $source = Album::create($library, 'Abbey Road (Remaster)', 'album', year: 1969, label: 'Apple');

        $albums = $this->createStub(AlbumPortInterface::class);
        $albums->method('findByUuid')->willReturnCallback(
            static fn (Uuid $id): ?Album => match (true) {
                $id->equals($target->getId()) => $target,
                $id->equals($source->getId()) => $source,
                default => null,
            },
        );
        $songs = $this->createStub(SongPortInterface::class);
        $songs->method('findByAlbum')->willReturn([]);

        $merged = (new AlbumMergeService($albums, $songs))->mergeAlbums($target->getId(), $source->getId());

        self::assertNull($merged->getLabel());
        self::assertSame(1969, $merged->getYear());
        self::assertTrue($merged->isFieldLocked('label'));
    }
}
