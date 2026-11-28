<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\Message\RefreshVideoMatchMessage;
use App\Catalog\Application\Port\VideoMatchRefresherInterface;
use App\Catalog\Application\Port\VideoMatchRefreshStoreInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Application\Exception\ServiceUnavailableException;
use Psr\Log\LoggerInterface;

final readonly class VideoMatchRefresh implements VideoMatchRefresherInterface
{
    public function __construct(
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private VideoMetadataPortInterface $providers,
        private VideoMatchTargetLoaderInterface $targets,
        private VideoMatchStructureLookup $structures,
        private VideoMatchRefreshStoreInterface $store,
        private LoggerInterface $logger,
    ) {}

    public function refresh(RefreshVideoMatchMessage $message): void
    {
        $kind = $message->kind;
        $title = $kind === VideoKind::Series
            ? $this->catalog->findSeriesByUuid($message->titleId)
            : $this->movies->findByUuid($message->titleId);
        $provider = VideoProviderId::tryFrom($title?->getMatchedProvider() ?? '');
        $entryId = $title?->getProviderId();
        if ($title === null || $provider === null || $entryId === null || !$provider->serves($kind)) {
            return;
        }

        $request = new VideoRequest(
            kind: $kind,
            title: $title instanceof TvSeries ? $title->getName() : $title->getTitle(),
            year: $title->getYear(),
            externalIds: [$provider->value => $entryId],
            ordering: $title instanceof TvSeries ? $title->getOrdering() : 'aired',
            libraryId: $title->getLibraryId(),
            sidecars: $provider === VideoProviderId::Local ? $this->targets->forTitle($title)->request->sidecars : [],
            titleId: $message->titleId,
        );
        $answer = $this->providers->identify($provider, $request);
        if (!$answer instanceof VideoTitle) {
            $this->providerFailure($provider, $request, $answer);

            return;
        }
        if ($answer->entry->id !== $entryId) {
            $this->providerFailure($provider, $request, VideoProviderProblem::Unavailable);

            return;
        }
        $match = $this->structures->resolve($provider, $request, $answer, allowDefault: false);
        if ($match instanceof VideoProviderProblem || $match === null) {
            $this->providerFailure($provider, $request, $match);

            return;
        }

        $this->store->apply($message, $provider, $request, $match);
    }

    private function providerFailure(VideoProviderId $provider, VideoRequest $request, ?VideoProviderProblem $problem): void
    {
        $context = ['provider' => $provider->value, 'title_id' => $request->titleId?->toString()];
        if (in_array($problem, [VideoProviderProblem::Unavailable, VideoProviderProblem::LoginFailed], true)) {
            throw new ServiceUnavailableException('The matched video provider could not refresh its saved entry.', $context);
        }
        if ($problem === null || $problem === VideoProviderProblem::Refused) {
            $this->logger->warning('The matched video provider returned no saved entry to refresh.', $context);
        }
    }
}
