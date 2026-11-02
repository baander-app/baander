<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PlaybackControlDeliveryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function selectingActions(): iterable
    {
        yield 'load' => ['load'];
        yield 'play' => ['play'];
        yield 'seek' => ['seek'];
    }

    #[DataProvider('selectingActions')]
    public function testUnchangedQueueStillAuthorizesSelectedMediaWithoutRepeatingQueueChecks(string $action): void
    {
        $owner = new Uuid();
        $queue = array_map(static fn(): string => new PublicId()->toString(), range(1, 77));
        $session = ListeningSession::create($owner, $queue, 0, 4);
        $session->control('load', new PlaybackMedia('song', PublicId::fromString($queue[0])), null, null, null, PlaybackState::Paused);
        $access = $this->createMock(PlaybackMediaAccessInterface::class);
        $access->expects(self::once())->method('assertTrackAccess')->with($owner, self::callback(static fn(PublicId $id): bool => $id->toString() === $queue[0]));
        $store = $this->store($session);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => $session->revision(), 'action' => $action];
        if ($action === 'load') {
            $request += ['media' => $session->media()->toArray(), 'queue' => $queue, 'currentTrackIndex' => 0];
        } elseif ($action === 'seek') {
            $request['position'] = 8;
        }

        $this->coordinator($store, $access)->execute($owner, PlaybackOperation::Control, $request);
    }

    public function testChangedQueueAuthorizesEveryEntry(): void
    {
        $owner = new Uuid();
        $first = new PublicId();
        $second = new PublicId();
        $session = ListeningSession::create($owner, [$first->toString()], 0, 0);
        $access = $this->createMock(PlaybackMediaAccessInterface::class);
        $checked = [];
        $access->expects(self::exactly(2))->method('assertTrackAccess')->willReturnCallback(static function (Uuid $owner, PublicId $id) use (&$checked): void { $checked[] = $id->toString(); });

        $this->coordinator($this->store($session), $access)->execute($owner, PlaybackOperation::Control, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'action' => 'queue',
            'queue' => [$first->toString(), $second->toString()], 'currentTrackIndex' => 0,
        ]);

        self::assertSame([$first->toString(), $second->toString()], $checked);
    }

    public function testControlDeliveryContextRequiresEndpointCapability(): void
    {
        $owner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $store = $this->store($session, 0);
        $store->expects(self::never())->method('insertCommand');
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('An endpoint capability is required.');

        $this->coordinator($store)->execute($owner, PlaybackOperation::Control, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'action' => 'pause', 'incarnationId' => new Uuid()->toString(),
        ]);
    }

    public function testControlAuthenticatesDeliveryEndpointBeforeReturningItsSnapshot(): void
    {
        $owner = new Uuid();
        $incarnation = new Uuid()->toString();
        $session = ListeningSession::create($owner, [], 0, 0);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::once())->method('endpoint')->with($owner->toString(), $incarnation, 'delivery-capability')->willReturn(['id' => $incarnation]);
        $store->expects(self::once())->method('snapshot')->with($session, $incarnation)->willReturn(['commands' => []]);

        $result = $this->coordinator($store)->execute($owner, PlaybackOperation::Control, [
            'requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'action' => 'pause', 'incarnationId' => $incarnation,
        ], 'delivery-capability');

        self::assertSame([], $result['commands']);
    }

    public function testDeliveryIdentityDoesNotChangeLogicalRequestDigestOrRetargetRetry(): void
    {
        $owner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $store = $this->store($session, 2);
        $digests = [];
        $store->method('endpoint')->willReturn([]);
        $store->expects(self::exactly(2))->method('rememberRequest')->willReturnCallback(static function (string $session, string $request, string $digest) use (&$digests): bool { $digests[] = $digest; return count($digests) === 2; });
        $store->expects(self::once())->method('insertCommand');
        $coordinator = $this->coordinator($store);
        $request = ['requestId' => new Uuid()->toString(), 'expectedRevision' => 0, 'action' => 'pause'];
        $coordinator->execute($owner, PlaybackOperation::Control, $request + ['incarnationId' => new Uuid()->toString()], 'first');
        $coordinator->execute($owner, PlaybackOperation::Control, $request + ['incarnationId' => new Uuid()->toString()], 'second');

        self::assertSame($digests[0], $digests[1]);
        self::assertSame(1, $session->revision());
    }

    private function store(ListeningSession $session, int $snapshots = 1): PlaybackStoreInterface&MockObject
    {
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->expects(self::exactly($snapshots))->method('snapshot')->willReturn([]);
        return $store;
    }

    private function coordinator(PlaybackStoreInterface $store, ?PlaybackMediaAccessInterface $access = null): PlaybackCoordinator
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transaction, $access ?? $this->createStub(PlaybackMediaAccessInterface::class));
    }
}
