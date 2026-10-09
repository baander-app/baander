<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\CommandHandler\Cover;

use App\Catalog\Application\Command\Cover\CoverOwner;
use App\Catalog\Application\Command\Cover\RemoveCoverCommand;
use App\Shared\Application\Exception\NotFoundException;

final class RemoveCoverHandlerTest extends CoverTestCase
{
    public function testRemovingDeletesTheImageItsFilesAndClearsTheOwner(): void
    {
        $album = $this->album();
        $image = $this->existingCover($album, 'images/album/' . $album->getId()->toString() . '/cover.jpg');

        ($this->removeCoverHandler())(new RemoveCoverCommand(CoverOwner::Album, $album->getPublicId()->toString()));

        self::assertNull($this->storedCovers[$album->getPublicId()->toString()]);
        self::assertNull($this->images->findByUuid($image->getId()));
        self::assertSame([], $this->storedFiles());
    }

    public function testAnOwnerWithoutACoverIsNotFound(): void
    {
        $artist = $this->artist();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(sprintf('Artist "%s" has no cover.', $artist->getPublicId()->toString()));

        ($this->removeCoverHandler())(new RemoveCoverCommand(CoverOwner::Artist, $artist->getPublicId()->toString()));
    }

    public function testAFailedOwnerSaveKeepsTheImageAndItsFiles(): void
    {
        $album = $this->album();
        $image = $this->existingCover($album, 'images/album/cover.jpg');
        $files = $this->storedFiles();
        $this->ownerSaveFailure = new \RuntimeException('database unavailable');

        try {
            ($this->removeCoverHandler())(new RemoveCoverCommand(CoverOwner::Album, $album->getPublicId()->toString()));
            self::fail('The failed save must surface.');
        } catch (\RuntimeException $exception) {
            self::assertSame('database unavailable', $exception->getMessage());
        }

        self::assertNotNull($this->images->findByUuid($image->getId()));
        self::assertSame($files, $this->storedFiles());
    }
}
