<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Shared\Domain\Model\Uuid;

interface VideoMatchTargetLoaderInterface
{
    public function load(VideoMatchState $state): ?VideoMatchTarget;

    public function forTitle(Movie|TvSeries $title): VideoMatchTarget;

    /**
     * @param list<string> $relativePaths
     * @return list<VideoMatchTarget>
     */
    public function forObservations(Uuid $libraryId, array $relativePaths): array;
}
