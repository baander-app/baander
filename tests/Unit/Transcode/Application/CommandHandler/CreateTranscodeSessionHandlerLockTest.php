<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\CommandHandler;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Transcode\FreeTranscodeSessionShare;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\HeldTranscodeSession;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Exception\TranscodeSessionLimitException;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\EncodePresentationPort;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeSessionSharePort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AacImplementation;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use App\Transcode\Domain\ValueObject\QualityTier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CreateTranscodeSessionHandlerLockTest extends TestCase
{
    use FreeTranscodeSessionShare;

    private TranscodeJobPortInterface&MockObject $jobs;
    private TranscodeSessionSharePort&MockObject $share;
    /** @var list<string> */
    private array $locks = [];
    private TranscodeSessionPortInterface&MockObject $sessions;
    private TranscodeExecutionOwnershipPort&MockObject $ownership;
    private TranscodeJob $job;
    private TranscodeSession $session;
    private CreateTranscodeSessionCommand $command;
    private CreateTranscodeSessionHandler $handler;
    private bool $insideTransaction = false;
    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $video = Uuid::generate();
        $user = Uuid::generate();
        $profile = AudioProfile::streamingStereo();
        $tier = QualityTier::p720();
        $this->job = TranscodeJob::create($video, $tier, '/fixture/video', $user);
        $this->session = TranscodeSession::create($user, $this->job->getId(), $video, $profile);
        $this->command = new CreateTranscodeSessionCommand($user, $video, $tier, $profile);
        $this->jobs = $this->createMock(TranscodeJobPortInterface::class);
        $this->sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $this->ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $this->share = $this->createMock(TranscodeSessionSharePort::class);
        // Every start takes the user's lock, also one that hands an existing session back.
        $this->share->expects(self::once())->method('lockUser')->willReturnCallback(function (Uuid $locked) use ($user): void {
            self::assertTrue($this->insideTransaction);
            self::assertTrue($locked->equals($user));
            $this->locks[] = 'user';
        });
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn('/fixture/video');
        $selection = new PresentationSelection('encode', 'source', 0, 1, 'mp4', 'configuration');
        $presentations = $this->createStub(EncodePresentationPort::class);
        $presentations->method('select')->willReturn($selection);
        $this->jobs->expects(self::once())->method('getOrCreateJob')->with($video, $tier, '/fixture/video', $user, [], $selection, $this->command->requestId)->willReturn($this->job);
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->insideTransaction = true;
            try {
                return $operation();
            } finally {
                $this->insideTransaction = false;
            }
        });
        $sleeper = $this->createStub(SleeperInterface::class);
        $sleeper->method('sleep')->willReturnCallback(function (float $seconds): void {
            self::assertFalse($this->insideTransaction, 'Waiting cannot hold the producer transaction.');
            $this->sleeps[] = $seconds;
        });
        $this->handler = new CreateTranscodeSessionHandler(new MediaExecutionPolicy(new DeploymentProfile()), $this->jobs, $this->sessions, $storage, $this->ownership, $transaction, $sleeper, $presentations, $this->settings(), $this->freeSessionShare($this->share));
    }

    private function snapshot(string $outcome): TranscodeRuntimeSnapshot
    {
        return new TranscodeRuntimeSnapshot($this->job->getId(), $this->session->getId(), $this->command->requestId, new DesiredTranscodeState($this->command->getAudioProfile()), 1, 0, 1, $outcome, null, null, in_array($outcome, ['accepted', 'ready'], true));
    }

    public function testDurableIntentIsWrittenInsideTransactionAndStartupWaitsForAcceptance(): void
    {
        $this->ownership->expects(self::once())->method('lockAdmission')->willReturnCallback(function (): void {
            self::assertTrue($this->insideTransaction);
            $this->locks[] = 'video';
        });
        $this->share->expects(self::once())->method('heldBy')->willReturn([]);
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->jobs->expects(self::once())->method('save');
        $this->ownership->expects(self::once())->method('admit')->willReturnCallback(function (): TranscodeRuntimeSnapshot {
            self::assertTrue($this->insideTransaction);
            return $this->snapshot('pending');
        });
        $this->ownership->method('find')->willReturnOnConsecutiveCalls(null, $this->snapshot('pending'), $this->snapshot('accepted'));
        self::assertSame($this->session, ($this->handler)($this->command));
        self::assertSame([0.1], $this->sleeps);
        self::assertSame(['user', 'video'], $this->locks, 'The user\'s lock comes before the video row.');
    }

    public function testAStartTheUsersShareCannotMakeRoomForCreatesNoSession(): void
    {
        $held = array_map(
            fn (int $index): HeldTranscodeSession => new HeldTranscodeSession(Uuid::generate(), Uuid::generate(), Uuid::generate(), 'other-' . $index, '720p', new \DateTimeImmutable(), true),
            range(1, 6),
        );
        $this->share->method('heldBy')->willReturn($held);
        $this->share->expects(self::never())->method('lock');
        $this->sessions->expects(self::never())->method('createSession');
        $this->jobs->expects(self::never())->method('save');
        $this->ownership->expects(self::never())->method('admit');
        $this->expectException(TranscodeSessionLimitException::class);
        ($this->handler)($this->command);
    }

    public function testExistingAcceptedSessionIsReusedWithoutAnotherAdmission(): void
    {
        $this->share->expects(self::never())->method('heldBy');
        $this->ownership->method('find')->willReturn($this->snapshot('accepted'));
        $this->sessions->expects(self::once())->method('findByUuid')->with($this->session->getId())->willReturn($this->session);
        $this->sessions->expects(self::never())->method('createSession');
        $this->jobs->expects(self::never())->method('save');
        $this->ownership->expects(self::never())->method('admit');
        self::assertSame($this->session, ($this->handler)($this->command));
        self::assertSame([], $this->sleeps);
    }

    public function testEndedSessionOfARunningExecutionIsNotHandedBack(): void
    {
        $ended = $this->snapshot('accepted');
        $this->session->markCancelled();
        $replacement = TranscodeSession::create($this->session->getUserId(), $this->job->getId(), $this->session->getVideoId(), $this->command->getAudioProfile());
        $admitted = new TranscodeRuntimeSnapshot($this->job->getId(), $replacement->getId(), $this->command->requestId, $ended->desired, 2, 0, 2, 'accepted', null, null, true);
        $this->ownership->method('find')->willReturnOnConsecutiveCalls($ended, $ended, $admitted);
        $this->sessions->expects(self::once())->method('findByUuid')->with($this->session->getId())->willReturn($this->session);
        $this->sessions->expects(self::once())->method('createSession')->willReturn($replacement);
        $this->jobs->expects(self::once())->method('save');
        $this->ownership->expects(self::once())->method('admit')->with($this->job->getId(), $replacement->getId(), $this->command->requestId, self::anything())->willReturn($admitted);
        self::assertSame($replacement, ($this->handler)($this->command));
    }

    public function testAnotherClientRuntimeIsNeverReused(): void
    {
        $accepted = $this->snapshot('accepted');
        $otherClient = new TranscodeRuntimeSnapshot($accepted->jobId, $accepted->sessionId, 'other-client', $accepted->desired, 1, 0, 1, 'accepted', null, null, true);
        $this->ownership->method('find')->willReturnOnConsecutiveCalls($otherClient, $otherClient, $accepted);
        $this->sessions->expects(self::never())->method('findByUuid');
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->ownership->expects(self::once())->method('admit')->willReturn($this->snapshot('pending'));
        self::assertSame($this->session, ($this->handler)($this->command));
    }

    public function testExpiredAcceptanceDoesNotReportOwnedExecution(): void
    {
        $accepted = $this->snapshot('accepted');
        $expired = new TranscodeRuntimeSnapshot($accepted->jobId, $accepted->sessionId, $accepted->requestId, $accepted->desired, $accepted->desiredRevision, $accepted->observedRevision, $accepted->generation, 'accepted', null, null, false);
        $this->ownership->method('find')->willReturn($expired);
        $this->sessions->expects(self::once())->method('findByUuid')->willReturn($this->session);
        $this->sessions->expects(self::never())->method('createSession');
        $this->ownership->expects(self::once())->method('expireAdmission')->willReturn(false);
        $this->expectException(TranscodeStartupUnavailableException::class);
        ($this->handler)($this->command);
    }

    public function testStickyClientCanRestartAfterRefusedAdmission(): void
    {
        $this->assertStickyClientRestarts('refused');
    }

    public function testStickyClientCanRestartAfterCancelledAdmission(): void
    {
        $this->job->markCancelled();
        $this->assertStickyClientRestarts('cancelled');
    }

    public function testStickyClientCanRestartAfterFailedAdmission(): void
    {
        $this->job->markFailed('Encoder stopped.');
        $this->assertStickyClientRestarts('failed');
    }

    private function assertStickyClientRestarts(string $outcome): void
    {
        $this->ownership->method('find')->willReturnOnConsecutiveCalls($this->snapshot($outcome), $this->snapshot($outcome), $this->snapshot('accepted'));
        $this->sessions->expects(self::never())->method('findByUuid');
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->ownership->expects(self::once())->method('admit')->with($this->job->getId(), $this->session->getId(), $this->command->requestId, self::callback(static fn (DesiredTranscodeState $desired): bool => $desired->aacImplementation === AacImplementation::FdkAac))->willReturn($this->snapshot('pending'));
        self::assertSame($this->session, ($this->handler)($this->command));
    }

    public function testUnacceptedStartupExpiresTheDurableRequestAfterTheBound(): void
    {
        $this->ownership->method('find')->willReturnOnConsecutiveCalls(null, ...array_fill(0, 50, $this->snapshot('pending')));
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->ownership->method('admit')->willReturn($this->snapshot('pending'));
        $this->ownership->expects(self::once())->method('expireAdmission')->with($this->job->getId(), $this->session->getId(), $this->command->requestId)->willReturn(true);
        $this->expectException(TranscodeStartupUnavailableException::class);
        try {
            ($this->handler)($this->command);
        } finally {
            self::assertSame(array_fill(0, 50, 0.1), $this->sleeps);
        }
    }

    public function testAcceptanceRacingTimeoutIsReturnedWithoutRefusingItsOwner(): void
    {
        $this->ownership->method('find')->willReturnOnConsecutiveCalls(null, ...[...array_fill(0, 50, $this->snapshot('pending')), $this->snapshot('accepted')]);
        $this->sessions->expects(self::once())->method('createSession')->willReturn($this->session);
        $this->ownership->method('admit')->willReturn($this->snapshot('pending'));
        $this->ownership->expects(self::once())->method('expireAdmission')->willReturn(false);
        self::assertSame($this->session, ($this->handler)($this->command));
    }

    private function settings(): SystemSettingsPortInterface
    {
        $settings = $this->createStub(SystemSettingsPortInterface::class);
        // A non-default position, so the admitted state shows it came from the setting.
        $settings->method('get')->willReturn('fdk_aac');
        return $settings;
    }
}
