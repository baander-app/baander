<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Catalog\Domain\ReadModel\VideoPlacementPath;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final readonly class VideoAvailability implements VideoAvailabilityPortInterface
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private LibraryMediaFilesInterface $files,
    ) {}

    public function availablePaths(array $videoIds, LibraryReadScope $scope): array
    {
        $libraries = [];
        foreach ($this->videos->placementPaths($videoIds, $scope) as $path) {
            $libraries[$path->libraryId->toString()][] = $path;
        }
        $available = [];
        foreach ($libraries as $paths) {
            $absolutePaths = array_map(static fn (VideoPlacementPath $path): string => $path->absolutePath, $paths);
            $hashes = $this->files->findAvailableHashesByPaths($paths[0]->libraryId, $absolutePaths);
            foreach ($paths as $path) {
                if (($hashes[$path->absolutePath] ?? null) === $path->hash) {
                    $available[$path->videoId->toString()][] = $path;
                }
            }
        }

        return $available;
    }

    public function forVideos(array $videoIds, LibraryReadScope $scope): array
    {
        $paths = $this->availablePaths($videoIds, $scope);
        $available = [];
        foreach ($videoIds as $id) {
            $available[$id->toString()] = isset($paths[$id->toString()]);
        }

        return $available;
    }
}
