<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\ReadModel\VideoPlacementPath;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface VideoAvailabilityPortInterface
{
    /**
     * @param list<Uuid> $videoIds
     * @return array<string, list<VideoPlacementPath>> Indexed paths with a matching available hash, keyed by video UUID.
     */
    public function availablePaths(array $videoIds, LibraryReadScope $scope): array;

    /**
     * @param list<Uuid> $videoIds
     * @return array<string, bool> Last observed availability, keyed by video UUID.
     */
    public function forVideos(array $videoIds, LibraryReadScope $scope): array;
}
