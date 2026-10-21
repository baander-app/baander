<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Infrastructure;

use App\Library\Domain\Repository\LibraryFileIndexRepositoryInterface;
use App\Library\Infrastructure\LibraryFileAvailability;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class LibraryFileAvailabilityTest extends TestCase
{
    public function testReadsTheOwningLibraryIndexWithoutInspectingTheFilesystem(): void
    {
        $libraryId = new Uuid();
        $paths = ['/media/album/present.flac', '/media/album/missing.flac'];
        $availability = [$paths[0] => true, $paths[1] => false];
        $index = $this->createMock(LibraryFileIndexRepositoryInterface::class);
        $index->expects($this->once())->method('findAvailabilityByPaths')->with($libraryId, $paths)->willReturn($availability);

        self::assertSame($availability, (new LibraryFileAvailability($index))->findAvailabilityByPaths($libraryId, $paths));
    }
}
