<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Application\CommandHandler\VideoMatch\ApplyVideoMatchHandler;
use App\Catalog\Application\Port\VideoMatchApplyInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Repository\VideoProviderPauseRepositoryInterface;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Port\VideoMetadataPortInterface;
use Psr\Clock\ClockInterface;

final readonly class AutomaticVideoMatcher implements AutomaticVideoMatcherInterface
{
    private const int CANDIDATE_LIMIT = 5;

    public function __construct(
        private VideoMatchTargetLoaderInterface $targets,
        private VideoMetadataPortInterface $providers,
        private LibraryProviderOrderService $orders,
        private VideoProviderPauseRepositoryInterface $pauses,
        private VideoMatchConfidence $confidence,
        private VideoMatchApplyInterface $apply,
        private ClockInterface $clock,
        private VideoMatchStructureLookup $structures,
        private ApplyVideoMatchHandler $metadata,
    ) {}

    public function match(VideoMatchState $claim): VideoMatchOutcome
    {
        $target = $this->targets->load($claim);
        if ($target === null || ($target->matched && $claim->source === 'ingest')) {
            return new VideoMatchOutcome(VideoMatchOutcomeKind::Gone);
        }
        $request = $claim->source === 'admin' ? self::requestWithIds($target->request, $target->request->externalIds, 'aired') : $target->request;
        $usable = $this->providers->usableProviders($request->kind);
        $local = in_array(VideoProviderId::Local, $usable, true) && $target->hasTitleNfo()
            ? $this->providers->identify(VideoProviderId::Local, $request)
            : null;
        if ($local instanceof VideoTitle) {
            $request = self::requestWithIds($request, $request->externalIds + $local->entry->externalIds);
        }
        $library = $request->libraryId ?? throw new \LogicException('A video match target has a library.');
        $scored = [];
        $attempted = false;
        foreach ($this->orders->get($library)->getProviderIds() as $id) {
            $provider = VideoProviderId::from($id);
            if (!in_array($provider, $usable, true) || ($provider === VideoProviderId::Local && !$local instanceof VideoTitle)) {
                continue;
            }
            $pause = $this->pauses->find($provider->value);
            if ($pause !== null && $pause->retryAt > $this->clock->now()) {
                return new VideoMatchOutcome(VideoMatchOutcomeKind::RetryLater, $provider, reason: $pause->failureKind, retryAt: $pause->retryAt);
            }
            if ($provider === VideoProviderId::Local) {
                return $this->apply($claim, $target, $provider, $local);
            }
            if ($request->externalIds !== []) {
                $identified = $this->providers->identify($provider, $request);
                if ($identified instanceof VideoProviderProblem) {
                    $outcome = $this->problem($provider, $identified);
                    if ($outcome !== null) {
                        return $outcome;
                    }
                    continue;
                }
                $attempted = true;
                if ($identified !== null) {
                    return $this->apply($claim, $target, $provider, $identified);
                }
                $this->pauses->clear($provider->value);
                continue;
            }
            $hits = $this->providers->search($provider, $request);
            if ($hits instanceof VideoProviderProblem) {
                $outcome = $this->problem($provider, $hits);
                if ($outcome !== null) {
                    return $outcome;
                }
                continue;
            }
            $attempted = true;
            $ranked = [];
            foreach (array_slice($hits, 0, self::CANDIDATE_LIMIT) as $hit) {
                $details = $this->providers->identify($provider, self::requestWithIds($request, [$provider->value => $hit->id]));
                if ($details instanceof VideoProviderProblem) {
                    $outcome = $this->problem($provider, $details);
                    if ($outcome !== null) {
                        return $outcome;
                    }
                    continue;
                }
                if ($details !== null) {
                    $ranked[] = ['title' => $details, 'score' => $this->confidence->score($request, $details, $target->parses)];
                }
            }
            array_push($scored, ...$ranked);
            $decision = $this->confidence->decide($ranked);
            if ($decision['match'] !== null) {
                return $this->apply($claim, $target, $provider, $decision['match']);
            }
            $this->pauses->clear($provider->value);
        }
        if (!$attempted) {
            return new VideoMatchOutcome(VideoMatchOutcomeKind::AwaitingProvider);
        }
        $decision = $this->confidence->decide($scored);

        return new VideoMatchOutcome(VideoMatchOutcomeKind::NeedsReview, reason: $decision['reason'] ?? 'low_score', score: $decision['score']);
    }

    private function apply(VideoMatchState $claim, VideoMatchTarget $target, VideoProviderId $provider, VideoTitle $metadata): VideoMatchOutcome
    {
        $request = $target->request;
        if ($claim->source === 'admin') {
            $ordering = $metadata->orderings[0]->id ?? 'aired';
            $request = self::requestWithIds($request, $request->externalIds, $ordering);
            if ($ordering !== 'aired') {
                $default = $this->providers->identify($provider, self::requestWithIds($request, [$provider->value => $metadata->entry->id]));
                if ($default instanceof VideoProviderProblem) {
                    return $this->problem($provider, $default) ?? new VideoMatchOutcome(VideoMatchOutcomeKind::AwaitingProvider);
                }
                if ($default === null || $default->entry->id !== $metadata->entry->id) {
                    return new VideoMatchOutcome(VideoMatchOutcomeKind::Unavailable, $provider, reason: 'unavailable');
                }
                $metadata = $default;
            }
        }
        $match = $this->structures->resolve($provider, $request, $metadata, allowDefault: true);
        if ($match === null) {
            return new VideoMatchOutcome(VideoMatchOutcomeKind::NeedsReview, $provider, reason: 'ordering_unavailable');
        }
        if ($match instanceof VideoProviderProblem) {
            return $this->problem($provider, $match) ?? new VideoMatchOutcome(VideoMatchOutcomeKind::AwaitingProvider);
        }
        $library = $target->request->libraryId ?? throw new \LogicException('A video match target has a library.');
        $prepared = $this->metadata->prepare($library, $match->metadata);
        $committed = false;
        try {
            $committed = $this->apply->apply($claim, $target, $provider, $match, $prepared);
        } finally {
            $this->metadata->discard($prepared, $committed);
        }
        if (!$committed) {
            return new VideoMatchOutcome(VideoMatchOutcomeKind::RetryLater, $provider, retryAt: $this->clock->now()->modify('+15 seconds'));
        }
        $this->pauses->clear($provider->value);

        return new VideoMatchOutcome(VideoMatchOutcomeKind::Matched, $provider);
    }

    private function problem(VideoProviderId $provider, VideoProviderProblem $problem): ?VideoMatchOutcome
    {
        return match ($problem) {
            VideoProviderProblem::Disabled, VideoProviderProblem::NotConfigured => null,
            VideoProviderProblem::LoginFailed => new VideoMatchOutcome(VideoMatchOutcomeKind::Unavailable, $provider, reason: 'login_failed'),
            VideoProviderProblem::Unavailable => new VideoMatchOutcome(VideoMatchOutcomeKind::Unavailable, $provider, reason: 'unavailable'),
            VideoProviderProblem::Refused => new VideoMatchOutcome(VideoMatchOutcomeKind::RetryLater, $provider, reason: 'refused', retryAt: $this->clock->now()->modify('+5 minutes')),
        };
    }

    /** @param array<string, string> $externalIds */
    private static function requestWithIds(VideoRequest $request, array $externalIds, ?string $ordering = null): VideoRequest
    {
        return new VideoRequest(
            $request->kind,
            $request->title,
            $request->year,
            $externalIds,
            $ordering ?? $request->ordering,
            $request->libraryId,
            $request->sidecars,
            $request->titleId,
        );
    }
}
