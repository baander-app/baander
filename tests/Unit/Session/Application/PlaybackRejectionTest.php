<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Application;

use App\Media\Application\Port\PlaybackMediaAccessInterface;
use App\Session\Application\Port\PlaybackOperation;
use App\Session\Application\Port\PlaybackStoreInterface;
use App\Session\Application\Service\PlaybackCoordinator;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

final class PlaybackRejectionTest extends TestCase
{
    public function testExactTransferReplayReturnsItsStoredAttemptInsteadOfTheCurrentTransfer(): void
    {
        $owner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $store->method('rememberRequest')->willReturn(true);
        $request = new Uuid()->toString();
        $attempt = new Uuid()->toString();
        $store->expects(self::once())->method('requestedTransfer')->with($session->getId()->toString(), $request)
            ->willReturn(['id' => $attempt, 'request_id' => $request, 'state' => 'failed', 'rejection_reason' => 'video_codec_unsupported']);
        $store->expects(self::never())->method('saveTransfer');
        $store->method('snapshot')->willReturn(['transfer' => ['attemptId' => new Uuid()->toString()]]);
        $result = $this->coordinator($store)->execute($owner, PlaybackOperation::Transfer, [
            'requestId' => $request, 'expectedRevision' => 0, 'targetIncarnationId' => new Uuid()->toString(),
        ]);
        self::assertSame(['requestId' => $request, 'attemptId' => $attempt, 'state' => 'failed',
            'rejectionReason' => 'video_codec_unsupported'], $result['transferResult']);
        self::assertNotSame($result['transfer']['attemptId'], $result['transferResult']['attemptId']);
    }

    public function testDuplicateAcknowledgementCannotChangeItsReason(): void
    {
        [$coordinator, $owner, $input] = $this->acknowledgement();
        $input['rejectionReason'] = 'unknown';
        $this->expectException(ConflictException::class);
        $coordinator->execute($owner, PlaybackOperation::Acknowledge, $input, 'capability');
    }

    public function testDuplicateAcknowledgementWithTheSameReasonIsIdempotent(): void
    {
        [$coordinator, $owner, $input] = $this->acknowledgement();
        self::assertSame([], $coordinator->execute($owner, PlaybackOperation::Acknowledge, $input, 'capability'));
    }

    public function testApplicationRejectsRawErrorTextEvenWithoutHttpValidation(): void
    {
        [$coordinator, $owner, $input] = $this->acknowledgement();
        $input['rejectionReason'] = 'sensitive raw decoder error';
        $this->expectException(InvalidInputException::class);
        $coordinator->execute($owner, PlaybackOperation::Acknowledge, $input, 'capability');
    }

    /** @return array{PlaybackCoordinator, Uuid, array<string,mixed>} */
    private function acknowledgement(): array
    {
        $owner = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $store = $this->createMock(PlaybackStoreInterface::class);
        $store->method('lockSession')->willReturn($session);
        $incarnation = new Uuid()->toString();
        $attempt = new Uuid()->toString();
        $command = new Uuid()->toString();
        $store->method('endpoint')->willReturn(['id' => $incarnation]);
        $store->method('command')->willReturn(['incarnation_id' => $incarnation, 'attempt_id' => $attempt,
            'epoch' => 1, 'revision' => 0, 'outcome' => 'rejected', 'rejection_reason' => 'video_codec_unsupported',
            'acknowledged_at' => '2026-11-02T00:00:00Z']);
        $store->expects(self::never())->method('acknowledgeCommand');
        $store->method('snapshot')->willReturn([]);
        return [$this->coordinator($store), $owner, ['commandId' => $command, 'attemptId' => $attempt,
            'incarnationId' => $incarnation, 'epoch' => 1, 'revision' => 0, 'outcome' => 'rejected',
            'rejectionReason' => 'video_codec_unsupported']];
    }

    private function coordinator(PlaybackStoreInterface $store): PlaybackCoordinator
    {
        $transactions = $this->createStub(TransactionPortInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        return new PlaybackCoordinator($store, $transactions, $this->createStub(PlaybackMediaAccessInterface::class));
    }
}
