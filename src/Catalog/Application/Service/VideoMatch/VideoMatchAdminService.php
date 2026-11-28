<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\Port\VideoMatchAdminWriteInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReviewReadInterface;
use App\Catalog\Application\Query\VideoMatch\VideoMatchReview;
use App\Catalog\Domain\Model\VideoProviderPause;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Catalog\Application\Query\VideoMatch\VideoMatchTargetIdentity;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Library\Application\Port\LibraryTypeLookupInterface;
use App\Library\Domain\ValueObject\LibraryType;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class VideoMatchAdminService
{
    public function __construct(
        private VideoMatchReviewReadInterface $read,
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private VideoMatchTargetLoaderInterface $targets,
        private VideoMetadataPortInterface $providers,
        private VideoMatchAdminWriteInterface $write,
        private LibraryTypeLookupInterface $libraries,
        private MessageBusInterface $bus,
        private JobCancellationCheckpointInterface $cancellation,
    ) {}

    /** @return array<string, mixed> */
    public function review(int $limit = 50, ?string $cursor = null, ?string $kind = null, ?string $library = null, ?string $reason = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidInputException('The review limit must be between 1 and 100.', ['limit' => $limit]);
        }
        if ($kind !== null && !in_array($kind, ['series', 'movie', 'file'], true)) {
            throw new InvalidInputException('Unknown video review kind.');
        }
        $page = $this->read->page($cursor, $limit, $kind, $library === null ? null : $this->libraryId($library), $reason);

        return [
            'items' => array_map(static fn (VideoMatchReview $item): array => [
                'kind' => $item->kind,
                'publicId' => $item->publicId,
                'ownerKind' => $item->ownerKind,
                'ownerPublicId' => $item->ownerPublicId,
                'title' => $item->title,
                'path' => $item->path,
                'libraryPublicId' => $item->libraryPublicId,
                'libraryName' => $item->libraryName,
                'reason' => $item->reason,
                'checkedAt' => $item->checkedAt->format(\DateTimeInterface::ATOM),
                'bestScore' => $item->bestScore,
            ], $page->items),
            'nextCursor' => $page->nextCursor,
            'hasNextPage' => $page->hasNextPage,
            'perPage' => $page->perPage,
            'pausedProviders' => array_map(static fn (VideoProviderPause $pause): array => [
                'provider' => $pause->provider,
                'retryAt' => $pause->retryAt->format(\DateTimeInterface::ATOM),
                'failureKind' => $pause->failureKind,
            ], $page->pausedProviders),
        ];
    }

    /** @return array<string, mixed> */
    public function candidates(string $kind, string $publicId, ?string $query = null, string $view = 'search', string $collection = 'all', int $page = 1, int $limit = 50): array
    {
        if (!in_array($view, ['search', 'choices'], true)) {
            throw new InvalidInputException('The candidates view must be search or choices.');
        }
        if ($view === 'choices') {
            return $this->choices($kind, $publicId, $collection, $page, $limit);
        }
        [, $target] = $this->target($kind, $publicId);
        $request = $target->request;
        $title = $query === null || trim($query) === '' ? $request->title : trim($query);
        if (mb_strlen($title) > 200) {
            throw new InvalidInputException('The candidate query must be at most 200 characters.');
        }
        $search = self::request($request, title: $title);
        $results = [];
        foreach ($this->providers->usableProviders($request->kind) as $provider) {
            $this->cancellation->check();
            if ($provider === VideoProviderId::Local) {
                $identified = $this->providers->identify($provider, $search);
                $candidates = $identified instanceof VideoTitle ? [$identified->entry] : ($identified ?? []);
            } else {
                $candidates = $this->providers->search($provider, $search);
            }
            $results[] = [
                'provider' => $provider->value,
                'candidates' => $candidates instanceof VideoProviderProblem ? [] : array_map(static fn (VideoCandidate $candidate): array => [
                    'id' => $candidate->id,
                    'title' => $candidate->title,
                    'year' => $candidate->year,
                    'externalIds' => $candidate->externalIds,
                ], $candidates),
                'problem' => $candidates instanceof VideoProviderProblem ? self::problemName($candidates) : null,
            ];
        }

        return ['kind' => $kind, 'publicId' => $publicId, 'providers' => $results];
    }

    /** @return array<string, mixed> */
    private function choices(string $kind, string $publicId, string $collection, int $page, int $limit): array
    {
        if (!in_array($collection, ['all', 'orderings', 'episodes', 'files'], true)) {
            throw new InvalidInputException('The choices collection must be all, orderings, episodes or files.');
        }
        if ($page < 1 || $page > 1000000 || $limit < 1 || $limit > 100) {
            throw new InvalidInputException('Choose a page between 1 and 1000000 and a limit between 1 and 100.');
        }
        $identity = $this->identity($kind, $publicId);
        $orderings = [];
        $problem = null;
        if ($identity->kind === VideoKind::Series && in_array($collection, ['all', 'orderings'], true)) {
            [$orderings, $problem] = $this->orderingChoices($identity);
        }
        $episodes = in_array($collection, ['all', 'episodes'], true) ? $this->read->episodeChoices($identity, $page, $limit) : null;
        $files = in_array($collection, ['all', 'files'], true) ? $this->read->placementChoices($identity, $page, $limit) : null;

        return [
            'kind' => $kind, 'publicId' => $publicId, 'view' => 'choices', 'collection' => $collection,
            'provider' => $identity->matchedProvider, 'currentOrdering' => $identity->ordering,
            'orderings' => $orderings, 'orderingProblem' => $problem,
            'episodes' => $episodes === null ? null : self::choicePage($episodes, $page, $limit),
            'files' => $files === null ? null : self::choicePage($files, $page, $limit),
        ];
    }

    /** @return array{list<array{id: string, label: string, kind: string}>, ?string} */
    private function orderingChoices(VideoMatchTargetIdentity $identity): array
    {
        $provider = $identity->matchedProvider === null ? null : VideoProviderId::tryFrom($identity->matchedProvider);
        if ($provider === null || $identity->providerId === null) {
            return [[], 'unmatched'];
        }
        $target = $this->targetForIdentity($identity);
        $this->cancellation->check();
        $metadata = $this->providers->identify($provider, self::request($target->request, externalIds: [$provider->value => $identity->providerId], ordering: $identity->ordering ?? 'aired'));
        if (!$metadata instanceof VideoTitle) {
            return [[], $metadata instanceof VideoProviderProblem ? self::problemName($metadata) : 'not_found'];
        }
        if ($metadata->entry->id !== $identity->providerId) {
            return [[], 'refused'];
        }

        return [array_map(static fn (VideoOrdering $ordering): array => ['id' => $ordering->id, 'label' => $ordering->label, 'kind' => $ordering->kind], $metadata->orderings), null];
    }

    /**
     * @param array{items: list<array<string, mixed>>, total: int} $result
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, hasNextPage: bool}
     */
    private static function choicePage(array $result, int $page, int $limit): array
    {
        return $result + ['page' => $page, 'perPage' => $limit, 'hasNextPage' => $page * $limit < $result['total']];
    }

    /** @return array<string, mixed> */
    public function rematchTitle(string $kind, string $publicId, string $provider, string $entryId): array
    {
        [$identity, $target] = $this->target($kind, $publicId);
        $selected = $this->provider($provider, $target->request->kind);
        if ($selected === VideoProviderId::Local && $entryId !== $target->id->toString()) {
            throw new InvalidInputException('Choose the local metadata of this title.');
        }
        $metadata = $this->identify($selected, self::request($target->request, externalIds: [$selected->value => $entryId], ordering: 'aired'));
        $ordering = $target->request->kind === VideoKind::Series ? ($metadata->orderings[0]->id ?? throw new ConflictException('The provider offered no episode ordering.')) : null;
        if ($ordering !== null && $ordering !== 'aired') {
            $metadata = $this->identify($selected, self::request($target->request, externalIds: [$selected->value => $entryId], ordering: $ordering));
        }
        $aired = $this->airedFallback($selected, $target, $metadata, $ordering ?? 'aired');
        if (!$this->write->rematch($identity, $target, $selected, $metadata, $aired)) {
            throw new ConflictException('The title changed while its provider metadata was loaded. Reload and try again.');
        }

        return ['kind' => $kind, 'publicId' => $publicId, 'provider' => $provider, 'providerId' => $metadata->entry->id, 'ordering' => $ordering];
    }

    /** @return array{libraryId: string, totalTitles: int, handMatchedTitles: int, handAssignedFiles: int} */
    public function previewLibraryRematch(string $library): array
    {
        return $this->libraryPreview($this->libraryId($library));
    }

    /** @return array{libraryId: string, totalTitles: int, handMatchedTitles: int, handAssignedFiles: int} */
    private function libraryPreview(Uuid $libraryId): array
    {
        $preview = $this->read->previewLibrary($libraryId);

        return [
            'libraryId' => $preview->libraryPublicId,
            'totalTitles' => $preview->totalTitles,
            'handMatchedTitles' => $preview->handMatchedTitles,
            'handAssignedFiles' => $preview->handAssignedFiles,
        ];
    }

    /** @return array<string, mixed> */
    public function rematchLibrary(string $library, bool $confirmed = false, bool $force = false): array
    {
        $libraryId = $this->libraryId($library);
        $preview = $this->libraryPreview($libraryId);
        $queued = 0;
        $skipped = 0;
        if ($confirmed) {
            $cursor = null;
            do {
                $titles = $this->read->targetsByLibrary($libraryId, $cursor, 100);
                foreach ($titles as $title) {
                    $this->cancellation->check();
                    if ($this->write->requestLibraryTitle($title, $force)) {
                        ++$queued;
                    } else {
                        ++$skipped;
                    }
                    $cursor = $title;
                }
            } while (count($titles) === 100);
            if ($queued > 0) {
                $this->bus->dispatch(new DrainVideoMatchesMessage());
            }
        }

        return $preview + ['confirmed' => $confirmed, 'force' => $force, 'queued' => $queued, 'skipped' => $skipped];
    }

    /** @return array{publicId: string, ordering: string} */
    public function setOrdering(string $publicId, string $ordering): array
    {
        [$identity, $target] = $this->target('series', $publicId);
        if ($identity->matchedProvider === null || $identity->providerId === null) {
            throw new ConflictException('Match the series to a provider before changing its episode ordering.');
        }
        $provider = $this->provider($identity->matchedProvider, VideoKind::Series);
        $metadata = $this->identify($provider, self::request($target->request, externalIds: [$provider->value => $identity->providerId], ordering: $ordering));
        if (!$metadata->hasOrdering($ordering)) {
            throw new InvalidInputException('The provider does not offer that episode ordering.', ['ordering' => $ordering]);
        }
        $aired = $this->airedFallback($provider, $target, $metadata, $ordering);
        if (!$this->write->setOrdering($identity, $target, $ordering, $provider, $metadata, $aired)) {
            throw new ConflictException('The series changed while its provider metadata was loaded. Reload and try again.');
        }

        return ['publicId' => $publicId, 'ordering' => $ordering];
    }

    /**
     * @param array<array-key, string> $episodes
     * @return array{publicId: string, episodes: list<string>, extraKind: string|null}
     */
    public function assignFile(string $publicId, array $episodes = [], ?string $extraKind = null): array
    {
        if (!array_is_list($episodes)) {
            throw new InvalidInputException('Episodes must be a list of public IDs.');
        }
        if (($extraKind === null) === ($episodes === [])) {
            throw new InvalidInputException('Choose one extra kind or at least one episode.');
        }
        $placement = $this->read->findPlacement(CatalogInput::publicId($publicId)) ?? throw new NotFoundException('Video file placement not found.');
        $extra = $extraKind === null ? null : (ExtraKind::tryFrom($extraKind) ?? throw new InvalidInputException('Unknown extra kind.'));
        $episodeIds = [];
        $episodes = array_values(array_unique($episodes));
        foreach ($episodes as $episodeId) {
            $episode = $this->read->findEpisode(CatalogInput::publicId($episodeId)) ?? throw new NotFoundException('Episode not found.');
            if ($placement->getSeriesId()?->equals($episode->getSeriesId()) !== true || $episode->isRetired()) {
                throw new InvalidInputException('A file can be assigned only to active episodes of its own series.');
            }
            $episodeIds[] = $episode->getId();
        }
        if (!$this->write->assignFile($placement, $episodeIds, $extra)) {
            throw new ConflictException('The video file changed while its assignment was being prepared. Reload and try again.');
        }

        return ['publicId' => $publicId, 'episodes' => $episodes, 'extraKind' => $extraKind];
    }

    private function identity(string $kind, string $publicId): VideoMatchTargetIdentity
    {
        $type = match ($kind) {
            'series' => VideoKind::Series,
            'movie' => VideoKind::Movie,
            default => throw new InvalidInputException('The title kind must be series or movie.'),
        };
        return $this->read->findTarget($type->value, CatalogInput::publicId($publicId)) ?? throw new NotFoundException('Video title not found.');
    }

    /** @return array{VideoMatchTargetIdentity, VideoMatchTarget} */
    private function target(string $kind, string $publicId): array
    {
        $identity = $this->identity($kind, $publicId);

        return [$identity, $this->targetForIdentity($identity)];
    }

    private function targetForIdentity(VideoMatchTargetIdentity $identity): VideoMatchTarget
    {
        $title = $identity->kind === VideoKind::Series ? $this->catalog->findSeriesByUuid($identity->id) : $this->movies->findByUuid($identity->id);
        if ($title === null) {
            throw new NotFoundException('Video title not found.');
        }

        return $this->targets->forTitle($title);
    }

    private function libraryId(string $library): Uuid
    {
        $id = $this->libraries->resolveId($library, LibraryReadScope::unrestricted());
        if (!in_array($this->libraries->findType($id), [LibraryType::Movie, LibraryType::TvShow], true)) {
            throw new InvalidInputException('Only movie and TV libraries can be re-matched.');
        }
        return $id;
    }

    private function provider(string $provider, VideoKind $kind): VideoProviderId
    {
        $id = VideoProviderId::tryFrom($provider) ?? throw new InvalidInputException('Unknown video metadata provider.');
        if (!in_array($id, $this->providers->usableProviders($kind), true)) {
            throw new ConflictException('The selected provider is disabled or has no configured key.', ['provider' => $provider]);
        }
        return $id;
    }

    private function identify(VideoProviderId $provider, VideoRequest $request): VideoTitle
    {
        $metadata = $this->providers->identify($provider, $request);
        if ($metadata instanceof VideoProviderProblem) {
            $reason = self::problemName($metadata);
            if (in_array($metadata, [VideoProviderProblem::Unavailable, VideoProviderProblem::LoginFailed], true)) {
                throw new ServiceUnavailableException('The selected provider is unavailable. Try again later.', ['provider' => $provider->value, 'reason' => $reason]);
            }
            throw new ConflictException('The selected provider could not identify this title.', ['provider' => $provider->value, 'reason' => $reason]);
        }
        if ($metadata === null) {
            throw new NotFoundException('The selected provider has no matching title.');
        }
        if (isset($request->externalIds[$provider->value]) && $metadata->entry->id !== $request->externalIds[$provider->value]) {
            throw new ServiceUnavailableException('The provider returned a different title. Try again later.');
        }
        return $metadata;
    }

    private function airedFallback(VideoProviderId $provider, VideoMatchTarget $target, VideoTitle $metadata, string $ordering): ?VideoTitle
    {
        if ($target->request->kind !== VideoKind::Series || $ordering === 'aired'
            || !array_any($target->parses, static fn (array $parse): bool => is_int($parse['absoluteNumber'] ?? null))
            || array_any($metadata->orderings, static fn (VideoOrdering $offered): bool => $offered->kind === 'absolute')
        ) {
            return null;
        }
        return $this->identify($provider, self::request($target->request, externalIds: [$provider->value => $metadata->entry->id], ordering: 'aired'));
    }

    /** @param array<string, string>|null $externalIds */
    private static function request(VideoRequest $original, ?string $title = null, ?array $externalIds = null, ?string $ordering = null): VideoRequest
    {
        return new VideoRequest(
            $original->kind,
            $title ?? $original->title,
            $original->year,
            $externalIds ?? $original->externalIds,
            $ordering ?? $original->ordering,
            $original->libraryId,
            $original->sidecars,
            $original->titleId,
        );
    }

    private static function problemName(VideoProviderProblem $problem): string
    {
        return match ($problem) {
            VideoProviderProblem::Disabled => 'disabled',
            VideoProviderProblem::NotConfigured => 'not_configured',
            VideoProviderProblem::Unavailable => 'unavailable',
            VideoProviderProblem::LoginFailed => 'login_failed',
            VideoProviderProblem::Refused => 'refused',
        };
    }
}
