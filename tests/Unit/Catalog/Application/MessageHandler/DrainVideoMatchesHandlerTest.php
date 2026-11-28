<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application\MessageHandler;

use App\Catalog\Application\Message\DrainVideoMatchesMessage;
use App\Catalog\Application\MessageHandler\DrainVideoMatchesHandler;
use App\Catalog\Application\Service\VideoMatch\AutomaticVideoMatcherInterface;
use App\Catalog\Application\Service\VideoMatch\VideoMatchOutcome;
use App\Catalog\Application\Service\VideoMatch\VideoMatchOutcomeKind;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Model\VideoProviderPause;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Catalog\Domain\Repository\VideoProviderPauseRepositoryInterface;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Application\Port\TransactionPortInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class DrainVideoMatchesHandlerTest extends TestCase
{
    public function testAnOutagePausesOnlyItsProviderAndContinuesWithTheNextDueTitle(): void
    {
        $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
        $state = $this->state($now);
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->method('claimOldestDueRequest')->willReturn($state);
        $states->expects(self::once())->method('lockClaimForApply')->willReturn($state);
        $states->method('nextRequestDueAt')->willReturn($now);
        $pauses = $this->createMock(VideoProviderPauseRepositoryInterface::class);
        $retry = $now->modify('+5 minutes');
        $pauses->expects(self::once())->method('pause')->with('tvdb', 'unavailable')->willReturn(new VideoProviderPause('tvdb', 'unavailable', 1, $retry));
        $states->expects(self::once())->method('postponeClaimedRequest')->with($state->id, self::isInstanceOf(Uuid::class), $retry);
        $matcher = $this->createMock(AutomaticVideoMatcherInterface::class);
        $matcher->expects(self::once())->method('match')->with($state)->willReturn(new VideoMatchOutcome(VideoMatchOutcomeKind::Unavailable, VideoProviderId::Tvdb, 'unavailable'));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message, array $stamps): Envelope {
            self::assertInstanceOf(DrainVideoMatchesMessage::class, $message);
            self::assertSame(1_000, $stamps[0]->getDelay());
            return new Envelope($message, $stamps);
        });

        new DrainVideoMatchesHandler($states, $pauses, $matcher, $bus, new MockClock($now), new NullLogger(), $this->transaction())(new DrainVideoMatchesMessage());
    }

    public function testAFailedLoginPersistsItsSafeReasonForTheTitleAndProvider(): void
    {
        $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
        $state = $this->state($now);
        $retry = $now->modify('+5 minutes');
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->method('claimOldestDueRequest')->willReturn($state);
        $states->expects(self::once())->method('lockClaimForApply')->willReturn($state);
        $states->expects(self::once())->method('postponeClaimedRequest')->with($state->id, self::isInstanceOf(Uuid::class), $retry, 'login_failed');
        $pauses = $this->createMock(VideoProviderPauseRepositoryInterface::class);
        $pauses->expects(self::once())->method('pause')->with('tvdb', 'login_failed')->willReturn(new VideoProviderPause('tvdb', 'login_failed', 1, $retry));
        $matcher = $this->createStub(AutomaticVideoMatcherInterface::class);
        $matcher->method('match')->willReturn(new VideoMatchOutcome(VideoMatchOutcomeKind::Unavailable, VideoProviderId::Tvdb, 'login_failed'));

        new DrainVideoMatchesHandler($states, $pauses, $matcher, $this->createStub(MessageBusInterface::class), new MockClock($now), new NullLogger(), $this->transaction())(new DrainVideoMatchesMessage());
    }

    public function testAnExpiredClaimCannotPauseAProviderAfterItsSuccessor(): void
    {
        $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
        $state = $this->state($now);
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->method('claimOldestDueRequest')->willReturn($state);
        $states->expects(self::once())->method('lockClaimForApply')->willReturn(null);
        $states->expects(self::never())->method('postponeClaimedRequest');
        $pauses = $this->createMock(VideoProviderPauseRepositoryInterface::class);
        $pauses->expects(self::never())->method('pause');
        $matcher = $this->createStub(AutomaticVideoMatcherInterface::class);
        $matcher->method('match')->willReturn(new VideoMatchOutcome(VideoMatchOutcomeKind::Unavailable, VideoProviderId::Tvdb, 'unavailable'));

        new DrainVideoMatchesHandler($states, $pauses, $matcher, $this->createStub(MessageBusInterface::class), new MockClock($now), new NullLogger(), $this->transaction())(new DrainVideoMatchesMessage());
    }

    public function testNoUsableProviderLeavesTheClaimAwaitingWithoutAnotherRun(): void
    {
        $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
        $state = $this->state($now);
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->method('claimOldestDueRequest')->willReturn($state);
        $states->expects(self::once())->method('markClaimedAwaitingProvider')->with($state->id, self::isInstanceOf(Uuid::class), $now);
        $states->method('nextRequestDueAt')->willReturn(null);
        $matcher = $this->createStub(AutomaticVideoMatcherInterface::class);
        $matcher->method('match')->willReturn(new VideoMatchOutcome(VideoMatchOutcomeKind::AwaitingProvider));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        new DrainVideoMatchesHandler($states, $this->createStub(VideoProviderPauseRepositoryInterface::class), $matcher, $bus, new MockClock($now), new NullLogger(), $this->transaction())(new DrainVideoMatchesMessage());
    }

    public function testACompletedApplyIsNotCompletedAgainOutsideItsTransaction(): void
    {
        $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->method('claimOldestDueRequest')->willReturn($this->state($now));
        $states->expects(self::never())->method('completeClaim');
        $matcher = $this->createStub(AutomaticVideoMatcherInterface::class);
        $matcher->method('match')->willReturn(new VideoMatchOutcome(VideoMatchOutcomeKind::Matched));

        new DrainVideoMatchesHandler($states, $this->createStub(VideoProviderPauseRepositoryInterface::class), $matcher, $this->createStub(MessageBusInterface::class), new MockClock($now), new NullLogger(), $this->transaction())(new DrainVideoMatchesMessage());
    }

    public function testAnUnexpectedFailurePostponesOnlyItsTitle(): void
    {
        $now = new DateTimeImmutable('2026-11-28T12:00:00+00:00');
        $state = $this->state($now);
        $states = $this->createMock(VideoMatchStateRepositoryInterface::class);
        $states->method('claimOldestDueRequest')->willReturn($state);
        $states->expects(self::once())->method('postponeClaimedRequest')->with($state->id, self::isInstanceOf(Uuid::class), $now->modify('+1 hour'));
        $matcher = $this->createStub(AutomaticVideoMatcherInterface::class);
        $matcher->method('match')->willThrowException(new \RuntimeException('Unusable title data'));
        $pauses = $this->createMock(VideoProviderPauseRepositoryInterface::class);
        $pauses->expects(self::never())->method('pause');

        new DrainVideoMatchesHandler($states, $pauses, $matcher, $this->createStub(MessageBusInterface::class), new MockClock($now), new NullLogger(), $this->transaction())(new DrainVideoMatchesMessage());
    }

    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        return $transaction;
    }

    private function state(DateTimeImmutable $now): VideoMatchState
    {
        return new VideoMatchState(Uuid::v7(), Uuid::v7(), null, 'requested', 'ingest', $now, Uuid::v7(), $now->modify('+15 minutes'));
    }
}
