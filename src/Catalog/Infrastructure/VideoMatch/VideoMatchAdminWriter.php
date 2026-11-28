<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\VideoMatch;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Port\VideoMatchAdminWriteInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchTargetIdentity;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Clock\ClockInterface;

final readonly class VideoMatchAdminWriter implements VideoMatchAdminWriteInterface
{
    public function __construct(
        private TransactionPortInterface $transaction,
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private VideoMatchStateRepositoryInterface $states,
        private VideoPlacementService $placements,
        private ApplyVideoMatchHandler $metadata,
        private ClockInterface $clock,
    ) {}

    public function rematch(VideoMatchTargetIdentity $identity, VideoMatchTarget $target, VideoProviderId $provider, VideoTitle $metadata, ?VideoTitle $aired = null): bool
    {
        if (!self::ownsTarget($identity, $target)) {
            return false;
        }
        $prepared = $this->metadata->prepare($identity->libraryId, $metadata);
        $committed = false;
        try {
            $committed = $this->transaction->run(function () use ($identity, $provider, $metadata, $aired, $prepared): bool {
                $title = $this->lockTitle($identity->kind, $identity->id);
                if ($title === null || !self::sameTitle($identity, $title)) {
                    return false;
                }
                if ($title instanceof TvSeries) {
                    $default = $metadata->orderings[0]->id ?? null;
                    if ($default === null) {
                        return false;
                    }
                    $title->setOrdering($default, array_column($metadata->orderings, 'id'));
                    if (!$this->placements->apply(Uuid::v7(), $title, $provider, $metadata, $aired, resetAssignments: true)) {
                        return false;
                    }
                } else {
                    $this->placements->resetMovieAssignments($title->getId());
                }
                $externalIds = array_replace($title->getExternalIds(), $metadata->entry->externalIds, [$provider->value => $metadata->entry->id]);
                $title->match($provider->value, $metadata->entry->id, $externalIds, handMatched: true);
                $this->metadata->apply($title, $provider, $prepared, force: true);
                $this->save($title);
                $this->recordAdminMatch($identity);

                return true;
            });
            return $committed;
        } finally {
            $this->metadata->discard($prepared, $committed);
        }
    }

    public function setOrdering(VideoMatchTargetIdentity $identity, VideoMatchTarget $target, string $ordering, VideoProviderId $provider, VideoTitle $metadata, ?VideoTitle $aired = null): bool
    {
        if (!self::ownsTarget($identity, $target)) {
            return false;
        }
        return $this->transaction->run(function () use ($identity, $ordering, $provider, $metadata, $aired): bool {
            $series = $this->catalog->lockSeriesForPlacement($identity->id);
            if ($series === null || !self::sameTitle($identity, $series)) {
                return false;
            }
            $series->setOrdering($ordering, array_column($metadata->orderings, 'id'));
            if (!$this->placements->apply(Uuid::v7(), $series, $provider, $metadata, $aired)) {
                return false;
            }
            $this->catalog->saveSeries($series);
            $this->catalog->flush();
            $this->states->cancelSeriesRematch($series->getId(), $this->clock->now(), matched: true);

            return true;
        });
    }

    public function assignFile(VideoPlacement $placement, array $episodeIds, ?ExtraKind $extra): bool
    {
        return $this->transaction->run(function () use ($placement, $episodeIds, $extra): bool {
            $seriesId = $placement->getSeriesId();
            if ($seriesId !== null) {
                $series = $this->catalog->lockSeriesForPlacement($seriesId);
                if ($series === null) {
                    return false;
                }
                $changed = $this->placements->replaceAssignment(Uuid::v7(), $seriesId, $placement->getId(), $episodeIds, $extra);
                if ($changed) {
                    $this->states->cancelSeriesRematch($seriesId, $this->clock->now(), $series->getMatchedProvider() !== null);
                }
                return $changed;
            }
            $movieId = $placement->getMovieId();
            if ($movieId === null || $extra === null || $episodeIds !== []) {
                return false;
            }
            $movie = $this->lockTitle(VideoKind::Movie, $movieId);
            if (!$movie instanceof Movie) {
                return false;
            }
            $fresh = $this->catalog->findPlacement($placement->getLibraryId(), $placement->getVideoId());
            if ($fresh === null || !$fresh->getId()->equals($placement->getId()) || $fresh->getMovieId()?->equals($movieId) !== true) {
                return false;
            }
            $fresh->assignExtra($extra, assigned: true);
            $fresh->review(null);
            $this->catalog->savePlacement($fresh);
            $this->movies->removeVideoForImport($movieId, $fresh->getVideoId());
            $this->catalog->flush();
            $this->states->cancelMovieRematch($movieId, $this->clock->now(), $movie->getMatchedProvider() !== null);

            return true;
        });
    }

    public function requestLibraryTitle(VideoMatchTargetIdentity $target, bool $force): bool
    {
        return $this->transaction->run(function () use ($target, $force): bool {
            $title = $this->lockTitle($target->kind, $target->id);
            if ($title === null || !$title->getLibraryId()->equals($target->libraryId) || ($title->isHandMatched() && !$force)) {
                return false;
            }
            if ($title instanceof TvSeries) {
                $this->states->requestSeries($title->getId(), 'admin', $this->clock->now());
            } else {
                $this->states->requestMovie($title->getId(), 'admin', $this->clock->now());
            }
            return true;
        });
    }

    private function recordAdminMatch(VideoMatchTargetIdentity $identity): void
    {
        if ($identity->kind === VideoKind::Series) {
            $this->states->recordAdminSeriesMatched($identity->id, $this->clock->now());
        } else {
            $this->states->recordAdminMovieMatched($identity->id, $this->clock->now());
        }
    }

    private function save(Movie|TvSeries $title): void
    {
        if ($title instanceof Movie) {
            $this->movies->saveMatchIdentity($title);
        } else {
            $this->catalog->saveSeries($title);
            $this->catalog->flush();
        }
    }

    private function lockTitle(VideoKind $kind, Uuid $id): Movie|TvSeries|null
    {
        if ($kind === VideoKind::Series) {
            return $this->catalog->lockSeriesForPlacement($id);
        }

        return $this->movies->lockForPlacement($id);
    }

    private static function ownsTarget(VideoMatchTargetIdentity $identity, VideoMatchTarget $target): bool
    {
        return $identity->id->equals($target->id) && $identity->kind === $target->request->kind
            && $target->request->libraryId?->equals($identity->libraryId) === true;
    }

    private static function sameTitle(VideoMatchTargetIdentity $expected, Movie|TvSeries $fresh): bool
    {
        return $fresh->getLibraryId()->equals($expected->libraryId)
            && $fresh->getMatchedProvider() === $expected->matchedProvider
            && $fresh->getProviderId() === $expected->providerId
            && $fresh->isHandMatched() === $expected->handMatched
            && (!$fresh instanceof TvSeries || $fresh->getOrdering() === $expected->ordering);
    }
}
