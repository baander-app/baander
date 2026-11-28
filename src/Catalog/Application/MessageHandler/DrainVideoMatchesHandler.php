<?php

declare(strict_types=1);

namespace App\Catalog\Application\MessageHandler;

use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\Service\VideoMatch\AutomaticVideoMatcherInterface;
use App\Catalog\Application\Service\VideoMatch\VideoMatchOutcome;
use App\Catalog\Application\Service\VideoMatch\VideoMatchOutcomeKind;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Catalog\Domain\Repository\VideoProviderPauseRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\Port\TransactionPortInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class DrainVideoMatchesHandler
{
    private const int CLAIM_SECONDS = 900;

    public function __construct(
        private VideoMatchStateRepositoryInterface $states,
        private VideoProviderPauseRepositoryInterface $pauses,
        private AutomaticVideoMatcherInterface $matcher,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private TransactionPortInterface $transaction,
    ) {}

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(DrainVideoMatchesMessage $message): void
    {
        $claimId = Uuid::v7();
        $claim = $this->states->claimOldestDueRequest($this->clock->now(), $claimId, self::CLAIM_SECONDS);
        if ($claim !== null) {
            try {
                $outcome = $this->matcher->match($claim);
            } catch (\Throwable $failure) {
                $this->logger->error('Automatic matching of video title {state_id} failed.', ['state_id' => $claim->id->toString(), 'exception' => $failure]);
                $outcome = new VideoMatchOutcome(VideoMatchOutcomeKind::RetryLater, retryAt: $this->clock->now()->modify('+1 hour'));
            }
            $this->finish($claim, $claimId, $outcome);
        }
        $due = $this->states->nextRequestDueAt();
        if ($due !== null) {
            $delay = max(1_000, ($due->getTimestamp() - $this->clock->now()->getTimestamp()) * 1_000);
            $this->bus->dispatch(new DrainVideoMatchesMessage(), [new DelayStamp($delay)]);
        }
    }

    private function finish(VideoMatchState $claim, Uuid $claimId, VideoMatchOutcome $outcome): void
    {
        switch ($outcome->kind) {
            case VideoMatchOutcomeKind::Matched:
                // The apply transaction completed the claim with the title's identity.
                return;
            case VideoMatchOutcomeKind::Gone:
                $this->states->completeClaim($claim->id, $claimId, $this->clock->now());
                return;
            case VideoMatchOutcomeKind::AwaitingProvider:
                $this->states->markClaimedAwaitingProvider($claim->id, $claimId, $this->clock->now());
                return;
            case VideoMatchOutcomeKind::NeedsReview:
                $this->states->markClaimedForReview($claim->id, $claimId, $outcome->reason ?? 'no_candidates', $outcome->score, $this->clock->now());
                return;
            case VideoMatchOutcomeKind::Unavailable:
                $provider = $outcome->provider ?? throw new \LogicException('A provider outage identifies its provider.');
                $this->transaction->run(function () use ($claim, $claimId, $provider, $outcome): void {
                    if ($this->states->lockClaimForApply($claim->id, $claimId) === null) {
                        return;
                    }
                    $pause = $this->pauses->pause($provider->value, $outcome->reason ?? 'unavailable');
                    $this->states->postponeClaimedRequest($claim->id, $claimId, $pause->retryAt, $outcome->reason);
                });
                return;
            case VideoMatchOutcomeKind::RetryLater:
                $this->states->postponeClaimedRequest($claim->id, $claimId, $outcome->retryAt ?? throw new \LogicException('A deferred match has a retry time.'), $outcome->reason);
        }
    }
}
