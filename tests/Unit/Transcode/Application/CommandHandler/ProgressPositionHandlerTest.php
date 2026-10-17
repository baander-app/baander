<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Command\UpdateTranscodePositionCommand;
use App\Transcode\Application\CommandHandler\UpdateTranscodePositionHandler;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Service\TranscodePlaybackControl;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProgressPositionHandlerTest extends TestCase
{
    #[DataProvider('runtimeStates')]
    public function testProgressOnlyUpdatesTheCurrentRunningRevision(int $generation, int $revision, string $status, bool $accepted): void
    {
        $owner = Uuid::generate();
        $video = Uuid::generate();
        $job = TranscodeJob::create($video, QualityTier::p720(), '/media/progress', $owner);
        $session = TranscodeSession::create($owner, $job->getId(), $video, AudioProfile::streamingStereo());
        $runtime = new TranscodeRuntimeSnapshot($session->getJobId(), $session->getId(), 'admission', new DesiredTranscodeState(AudioProfile::streamingStereo(), $status, 60.0), $revision, $revision, $generation, 'ready', '/generation-'.$generation, null, true);
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $sessions->method('findActiveByUserAndVideo')->willReturn([]);
        $sessions->expects($accepted ? self::once() : self::never())->method('save');
        $ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $ownership->expects(self::once())->method('lockAdmission')->with($video);
        $ownership->expects(self::once())->method('lockSession')->with($session->getId());
        $ownership->method('find')->willReturn($runtime);
        $ownership->expects($accepted ? self::once() : self::never())->method('control')
            ->with($session->getJobId(), $session->getId(), self::anything(), null, null, 61.0, null, false)->willReturn($runtime);
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation) => $operation());
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByUuid')->willReturn($job);
        $handler = new UpdateTranscodePositionHandler(new TranscodePlaybackControl($sessions, $ownership, $transaction, $jobs));
        $handler(new UpdateTranscodePositionCommand($session->getId(), 61.0, 'progress', expectedGeneration: 3, expectedRevision: 5));
    }

    /** @return iterable<string, array{int, int, string, bool}> */
    public static function runtimeStates(): iterable
    {
        yield 'current playing' => [3, 5, 'running', true];
        yield 'recovered owner' => [4, 5, 'running', false];
        yield 'new seek revision' => [3, 6, 'running', false];
        yield 'paused cursor' => [3, 5, 'paused', false];
        yield 'cancelled cursor' => [3, 5, 'cancelled', false];
    }
}
