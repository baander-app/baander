<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\VideoMatch;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Dto\PreparedVideoMatchMetadata;
use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Catalog\Application\Port\VideoMatchApplyInterface;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Shared\Application\Port\TransactionPortInterface;
use LogicException;
use Psr\Clock\ClockInterface;

final readonly class VideoMatchApply implements VideoMatchApplyInterface
{
    public function __construct(
        private TransactionPortInterface $transaction,
        private VideoMatchStateRepositoryInterface $states,
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private ClockInterface $clock,
        private VideoPlacementService $placements,
        private ApplyVideoMatchHandler $metadata,
    ) {}

    public function apply(
        VideoMatchState $claim,
        VideoMatchTarget $target,
        VideoProviderId $provider,
        ResolvedVideoMatch $match,
        PreparedVideoMatchMetadata $prepared,
    ): bool {
        $claimId = $claim->claimId;
        $library = $target->request->libraryId;
        if ($claimId === null || !self::owns($claim, $target) || !$provider->serves($target->request->kind)
            || $prepared->metadata !== $match->metadata || $library === null || !$library->equals($prepared->libraryId)
        ) {
            return false;
        }

        return $this->transaction->run(function () use ($claim, $claimId, $target, $provider, $match, $prepared, $library): bool {
            // Lock the title before its FK-dependent claim row, as a title delete does.
            $title = $target->request->kind === VideoKind::Series
                ? $this->catalog->lockSeriesForPlacement($target->id)
                : $this->movies->lockForPlacement($target->id);
            if ($title === null || !$library->equals($title->getLibraryId())
                || ($title instanceof TvSeries && ($title->getOrdering() !== $target->request->ordering
                    || !$match->metadata->hasOrdering($match->ordering)))
            ) {
                return false;
            }
            $live = $this->states->lockClaimForApply($claim->id, $claimId);
            if ($live === null || !self::owns($live, $target)
                || $this->states->completeClaim($live->id, $claimId, $this->clock->now()) === null
            ) {
                return false;
            }

            if ($title instanceof TvSeries) {
                if ($title->getOrdering() !== $match->ordering) {
                    $title->setOrdering($match->ordering, array_column($match->metadata->orderings, 'id'));
                }
                if (!$this->placements->apply($claimId, $title, $provider, $match->metadata, $match->aired, resetAssignments: $live->source === 'admin')) {
                    throw new LogicException('The validated video ordering became unavailable during apply.');
                }
            }
            if ($title instanceof Movie && $live->source === 'admin') {
                $this->placements->resetMovieAssignments($title->getId());
            }
            $entry = $match->metadata->entry;
            $externalIds = array_replace($title->getExternalIds(), $entry->externalIds, [$provider->value => $entry->id]);
            $title->match($provider->value, $entry->id, $externalIds, $live->source === 'admin' ? false : $title->isHandMatched());
            if ($title instanceof Movie) {
                $this->movies->saveMatchIdentity($title);
            } else {
                $this->catalog->saveSeries($title);
            }
            $this->metadata->apply($title, $provider, $prepared, force: $live->source === 'admin');
            $this->catalog->flush();

            return true;
        });
    }

    private static function owns(VideoMatchState $state, VideoMatchTarget $target): bool
    {
        $owner = $target->request->kind === VideoKind::Series ? $state->seriesId : $state->movieId;

        return $owner?->equals($target->id) === true;
    }
}
