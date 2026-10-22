<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Catalog;

use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;

trait DiscoveredFileObservations
{
    /** @var array<string, string> */
    private array $availableHashes = [];

    private function observedFile(DiscoveredFile $file): DiscoveredFile
    {
        $this->availableHashes[$file->absolutePath] = $file->hash;

        return $file;
    }

    /** @template T of LibraryMediaFilesInterface&Stub
     * @param T $media
     * @return T
     */
    private function observedMediaFiles(LibraryMediaFilesInterface&Stub $media): LibraryMediaFilesInterface&Stub
    {
        foreach (['findAvailableHashesByPaths', 'lockAvailableHashesByPathsForImport'] as $method) {
            $media->method($method)->willReturnCallback(fn (Uuid $libraryId, array $paths): array => array_intersect_key($this->availableHashes, array_fill_keys($paths, true)));
        }

        return $media;
    }
}
