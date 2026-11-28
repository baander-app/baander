<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\VideoMatch;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Dto\PreparedVideoMatchMetadata;
use App\Catalog\Application\Dto\ResolvedVideoMatch;
use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Catalog\Application\Port\VideoMatchRefreshStoreInterface;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Infrastructure\VideoPlacementService;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Shared\Application\Port\TransactionPortInterface;
use Psr\Clock\ClockInterface;

final readonly class VideoMatchRefresher implements VideoMatchRefreshStoreInterface
{
    public function __construct(
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private VideoPlacementService $placements,
        private VideoMatchRefreshReview $review,
        private TransactionPortInterface $transaction,
        private ClockInterface $clock,
        private ApplyVideoMatchHandler $metadata,
    ) {}

    public function apply(
        RefreshVideoMatchMessage $message,
        VideoProviderId $provider,
        VideoRequest $request,
        ResolvedVideoMatch $match,
        ?PreparedVideoMatchMetadata $prepared,
    ): bool {
        $entry = $match->metadata->entry;
        $missingOrdering = $message->kind === VideoKind::Series && !$match->metadata->hasOrdering($match->ordering);
        if ($request->titleId?->equals($message->titleId) !== true || $message->kind !== $request->kind
            || $request->ordering !== $match->ordering || ($request->externalIds[$provider->value] ?? null) !== $entry->id
            || ($prepared === null && !$missingOrdering)
            || ($prepared !== null && ($prepared->metadata !== $match->metadata || $request->libraryId?->equals($prepared->libraryId) !== true))
        ) {
            return false;
        }

        return $this->transaction->run(function () use ($message, $request, $provider, $match, $entry, $prepared): bool {
            $titleId = $message->titleId;
            $kind = $message->kind;
            $title = $kind === VideoKind::Series ? $this->catalog->lockSeriesForPlacement($titleId) : $this->movies->lockForPlacement($titleId);
            if ($title === null || $request->libraryId?->equals($title->getLibraryId()) !== true
                || $title->getMatchedProvider() !== $provider->value || $title->getProviderId() !== $entry->id
                || ($title instanceof TvSeries && $title->getOrdering() !== $request->ordering)
            ) {
                return false;
            }
            if (!$this->review->lockForRefresh($kind, $titleId, $message->requestedAt)) {
                return false;
            }
            if ($title instanceof TvSeries && !$this->placements->apply($message->operationId, $title, $provider, $match->metadata, $match->aired)) {
                $this->review->recordMissingOrdering($titleId, $this->clock->now());

                return false;
            }

            $externalIds = array_replace($title->getExternalIds(), $entry->externalIds, [$provider->value => $entry->id]);
            $title->match($provider->value, $entry->id, $externalIds, $title->isHandMatched());
            if ($title instanceof Movie) {
                $this->movies->saveMatchIdentity($title);
            } else {
                $this->catalog->saveSeries($title);
            }
            $this->metadata->apply($title, $provider, $prepared ?? throw new \LogicException('The refresh has no prepared metadata.'));
            $this->catalog->flush();
            $this->review->recordRefreshed($kind, $titleId, $this->clock->now());

            return true;
        });
    }
}
