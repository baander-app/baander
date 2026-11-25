<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\Service;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\HeldTranscodeSession;
use App\Transcode\Application\DTO\TranscodeControlResult;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Exception\TranscodeSessionLimitException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionSharePort;
use App\Transcode\Application\Service\TranscodeSessionShare;
use App\Transcode\Application\Settings\TranscodeSettingDefinitions;
use App\Transcode\Domain\ValueObject\AudioProfile;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TranscodeSessionShareTest extends TestCase
{
    private const string NOW = '2026-11-25 20:00:00';

    private InMemorySessionShare $store;
    /** @var list<string> sessions whose execution was cancelled, by id */
    private array $cancelled = [];
    private Uuid $user;
    private Uuid $video;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionShare();
        $this->user = Uuid::generate();
        $this->video = Uuid::generate();
    }

    public function testAStartPastTheShareEndsTheUsersSessionWithTheOldestSignOfLife(): void
    {
        $oldest = $this->hold($this->user, '-10 minutes');
        $silent = $this->hold($this->user, '-3 minutes');
        foreach (range(1, 4) as $ignored) {
            $this->hold($this->user, '-5 seconds');
        }

        $this->share()->makeRoom($this->user, $this->video, 'new-client');

        self::assertSame([$oldest->sessionId->toString()], $this->store->ended);
        self::assertSame([$oldest->sessionId->toString()], $this->cancelled);
        self::assertNotContains($silent->sessionId->toString(), $this->store->ended);
    }

    public function testAStartIsRefusedWhileEveryOtherSessionReportedPlaybackWithinTwoMinutes(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->hold($this->user, '-119 seconds');
        }

        try {
            $this->share()->makeRoom($this->user, $this->video, 'new-client');
            self::fail('The start was admitted.');
        } catch (TranscodeSessionLimitException $refusal) {
            self::assertSame(['reason' => 'transcode_session_limit', 'limit' => 6], $refusal->details);
        }
        self::assertSame([], $this->store->ended);
        self::assertSame([], $this->store->locked);
    }

    public function testTheShareComesFromTheServerSettingAndAppliesToEverySession(): void
    {
        $this->hold($this->user, '-5 seconds');
        $this->hold($this->user, '-5 seconds', complete: true);

        $this->expectException(TranscodeSessionLimitException::class);
        $this->share(limit: 2)->makeRoom($this->user, $this->video, 'new-client');
    }

    public function testSessionsOfThePlaybackTheStartContinuesNeverGiveWay(): void
    {
        foreach (range(1, 6) as $ignored) {
            $this->hold($this->user, '-1 hour', request: 'same-client');
        }

        $this->expectException(TranscodeSessionLimitException::class);
        $this->share()->makeRoom($this->user, $this->video, 'same-client');
    }

    public function testASessionThatReportedPlaybackSinceItWasReadIsNotEndedAndTheStartIsRefused(): void
    {
        $woke = $this->hold($this->user, '-10 minutes');
        foreach (range(1, 5) as $ignored) {
            $this->hold($this->user, '-5 seconds');
        }
        $this->store->renewOnLock[$woke->sessionId->toString()] = $this->at('-1 second');

        $this->expectException(TranscodeSessionLimitException::class);
        try {
            $this->share()->makeRoom($this->user, $this->video, 'new-client');
        } finally {
            self::assertSame([], $this->store->ended);
        }
    }

    public function testWhileAVideoSlotIsFreeOtherUsersSessionsAreUntouched(): void
    {
        $this->hold(Uuid::generate(), '-3 hours');
        $this->store->occupied = 7;

        $this->share()->makeRoom($this->user, $this->video, 'new-client');

        self::assertSame([], $this->store->ended);
        self::assertSame([], $this->store->slotQueries);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function slotHolders(): iterable
    {
        yield 'silent for 71 minutes' => ['-71 minutes', true];
        yield 'paused, signed 65 minutes ago' => ['-65 minutes', false];
    }

    #[DataProvider('slotHolders')]
    public function testAStartThatFindsNoFreeSlotEndsOnlyASessionSilentForSeventyMinutes(string $lastSignOfLife, bool $ended): void
    {
        $other = $this->hold(Uuid::generate(), $lastSignOfLife);
        $this->store->occupied = 8;

        $this->share()->makeRoom($this->user, $this->video, 'new-client');

        self::assertSame($ended ? [$other->sessionId->toString()] : [], $this->store->ended);
        self::assertEquals([$this->at('-70 minutes')], $this->store->slotQueries);
    }

    public function testASessionTheStartEndsForItsShareFreesItsSlot(): void
    {
        $own = $this->hold($this->user, '-10 minutes');
        foreach (range(1, 5) as $ignored) {
            $this->hold($this->user, '-5 seconds');
        }
        $other = $this->hold(Uuid::generate(), '-2 hours');
        $this->store->occupied = 8;

        $this->share()->makeRoom($this->user, $this->video, 'new-client');

        self::assertSame([$own->sessionId->toString()], $this->store->ended);
        self::assertNotContains($other->sessionId->toString(), $this->store->ended);
    }

    public function testAFinishedSessionEndsWithoutCancellingItsExecution(): void
    {
        $finished = $this->hold($this->user, '-1 hour', complete: true);
        foreach (range(1, 5) as $ignored) {
            $this->hold($this->user, '-5 seconds');
        }

        $this->share()->makeRoom($this->user, $this->video, 'new-client');

        self::assertSame([$finished->sessionId->toString()], $this->store->ended);
        self::assertSame([], $this->cancelled);
    }

    public function testATieredStartIsCheckedWholeAndEndsNothing(): void
    {
        $this->hold($this->user, '-10 minutes');
        foreach (range(1, 4) as $ignored) {
            $this->hold($this->user, '-5 seconds');
        }

        try {
            $this->share()->assertRoomFor($this->user, $this->video, 'new-client', ['360p', '720p', '1080p']);
            self::fail('Seven sessions would remain.');
        } catch (TranscodeSessionLimitException) {
        }
        self::assertSame([], $this->store->ended);
        self::assertSame([], $this->share()->assertRoomFor($this->user, $this->video, 'new-client', ['360p', '720p']));
        self::assertSame([], $this->store->ended, 'The check ends nothing; each tier makes its own room.');
    }

    public function testTiersThePlaybackHoldsAreHandedBackAndNeedNoRoom(): void
    {
        $held = $this->hold($this->user, '-5 seconds', request: 'same-client', tier: '360p');
        foreach (range(1, 6) as $ignored) {
            $this->hold($this->user, '-5 seconds');
        }

        self::assertEquals([$held->sessionId], $this->share()->assertRoomFor($this->user, $this->video, 'same-client', ['360p']));
        $this->expectException(TranscodeSessionLimitException::class);
        $this->share()->assertRoomFor($this->user, $this->video, 'same-client', ['360p', '720p']);
    }

    public function testWithdrawEndsEachSessionStillHeld(): void
    {
        $first = $this->hold($this->user, '-1 second');
        $gone = Uuid::generate();

        $this->share()->withdraw([$first->sessionId, $gone]);

        self::assertSame([$first->sessionId->toString()], $this->store->ended);
        self::assertSame([$first->sessionId->toString()], $this->cancelled);
    }

    private function share(int $limit = 6): TranscodeSessionShare
    {
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('control')->willReturnCallback(function (Uuid $job, Uuid $session, string $request, ?string $status = null): TranscodeControlResult {
            self::assertSame('cancelled', $status);
            $this->cancelled[] = $session->toString();
            $desired = new DesiredTranscodeState(AudioProfile::streamingStereo(), 'cancelled');

            return new TranscodeControlResult(new TranscodeRuntimeSnapshot($job, $session, $request, $desired, 2, 1, 1, 'cancelled', null, null), true);
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): int => $key === TranscodeSettingDefinitions::SESSIONS_PER_USER ? $limit : throw new \LogicException($key));

        return new TranscodeSessionShare($this->store, $ownership, $transaction, $settings, new DeploymentProfile(), new MockClock(self::NOW, 'UTC'));
    }

    private function hold(Uuid $user, string $lastSignOfLife, bool $complete = false, string $request = 'other-client', string $tier = '720p'): HeldTranscodeSession
    {
        $session = new HeldTranscodeSession(Uuid::generate(), Uuid::generate(), $this->video, $request, $tier, $this->at($lastSignOfLife), !$complete);
        $this->store->add($user, $session);

        return $session;
    }

    private function at(string $offset): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))->modify($offset);
    }
}

/** The port over arrays, oldest sign of life first as the database orders it. */
final class InMemorySessionShare implements TranscodeSessionSharePort
{
    public int $occupied = 0;
    /** @var list<string> */
    public array $ended = [];
    /** @var list<string> */
    public array $locked = [];
    /** @var list<DateTimeImmutable> */
    public array $slotQueries = [];
    /** @var array<string, DateTimeImmutable> a sign of life a session reports between its read and its lock */
    public array $renewOnLock = [];
    /** @var array<string, array{user: string, session: HeldTranscodeSession}> */
    private array $sessions = [];

    public function add(Uuid $user, HeldTranscodeSession $session): void
    {
        $this->sessions[$session->sessionId->toString()] = ['user' => $user->toString(), 'session' => $session];
    }

    public function lockUser(Uuid $userId): void
    {
    }

    public function heldBy(Uuid $userId): array
    {
        return $this->ordered(fn (array $entry): bool => $entry['user'] === $userId->toString());
    }

    public function occupiedVideoSlots(): int
    {
        return $this->occupied;
    }

    public function oldestSlotHolder(DateTimeImmutable $silentSince, array $excluding): ?HeldTranscodeSession
    {
        $this->slotQueries[] = $silentSince;
        $excluded = array_map(static fn (Uuid $id): string => $id->toString(), $excluding);

        return array_first($this->ordered(static fn (array $entry): bool => $entry['session']->holdsSlot
            && $entry['session']->lastSignOfLife <= $silentSince
            && !in_array($entry['session']->sessionId->toString(), $excluded, true)));
    }

    public function lock(Uuid $sessionId): ?HeldTranscodeSession
    {
        $this->locked[] = $sessionId->toString();
        $entry = $this->sessions[$sessionId->toString()] ?? null;
        if ($entry === null) {
            return null;
        }
        $renewed = $this->renewOnLock[$sessionId->toString()] ?? null;
        if ($renewed === null) {
            return $entry['session'];
        }
        $session = $entry['session'];

        return new HeldTranscodeSession($session->sessionId, $session->jobId, $session->videoId, $session->requestId, $session->tier, $renewed, $session->holdsSlot);
    }

    public function end(HeldTranscodeSession $session): void
    {
        $this->ended[] = $session->sessionId->toString();
        unset($this->sessions[$session->sessionId->toString()]);
        if ($session->holdsSlot) {
            --$this->occupied;
        }
    }

    /**
     * @param callable(array{user: string, session: HeldTranscodeSession}): bool $filter
     * @return list<HeldTranscodeSession>
     */
    private function ordered(callable $filter): array
    {
        $sessions = array_map(static fn (array $entry): HeldTranscodeSession => $entry['session'], array_values(array_filter($this->sessions, $filter)));
        usort($sessions, static fn (HeldTranscodeSession $a, HeldTranscodeSession $b): int => [$a->lastSignOfLife, $a->sessionId->toString()] <=> [$b->lastSignOfLife, $b->sessionId->toString()]);

        return $sessions;
    }
}
