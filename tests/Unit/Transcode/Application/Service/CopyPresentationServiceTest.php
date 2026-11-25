<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\Service;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\Transcode\FreeTranscodeSessionShare;
use App\Transcode\Application\DTO\CopyPresentationInput;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\HeldTranscodeSession;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Exception\TranscodeSessionLimitException;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Port\TranscodeSessionSharePort;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Service\CopyPresentationService;
use App\Transcode\Application\Service\MediaExecutionPolicy;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\PresentationSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CopyPresentationServiceTest extends TestCase
{
    use FreeTranscodeSessionShare;

    /** @var list<string> the start's steps in order */
    private array $steps = [];
    private bool $insideTransaction = false;

    /** @return iterable<string, array{string, bool, bool, bool, bool}> */
    public static function expiryRaces(): iterable
    {
        yield 'accepted during last sleep' => ['accepted', true, true, true, true];
        yield 'ready during last sleep' => ['ready', true, true, true, true];
        yield 'completed during last sleep' => ['complete', false, true, true, true];
        yield 'expired acceptance lease' => ['accepted', false, true, true, false];
        yield 'worker failed' => ['failed', true, true, true, false];
        yield 'replacement session' => ['ready', true, false, true, false];
        yield 'replacement request' => ['ready', true, true, false, false];
    }

    #[DataProvider('expiryRaces')]
    public function testAdmissionExpiryRechecksOnlyTheSameAcceptedExecution(string $outcome, bool $live, bool $sameSession, bool $sameRequest, bool $succeeds): void
    {
        $owner = new Uuid();
        $video = new Uuid();
        $requestId = new Uuid()->toString();
        $selection = new PresentationSelection('copy', 'source-hash', 0, 1, 'mp4');
        $job = TranscodeJob::createCopy($video, '/tmp/copy', $owner, $selection, 1920, 1080, $requestId);
        $session = TranscodeSession::create($owner, $job->getId(), $video, AudioProfile::streamingStereo());
        $desired = new DesiredTranscodeState(AudioProfile::streamingStereo(), presentationSelection: $selection);
        $pending = new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), $requestId, $desired, 1, 0, 1, 'pending', null, null);
        $accepted = new TranscodeRuntimeSnapshot(
            $job->getId(),
            $sameSession ? $session->getId() : new Uuid(),
            $sameRequest ? $requestId : new Uuid()->toString(),
            $desired,
            1,
            1,
            1,
            $outcome,
            null,
            null,
            $live,
        );
        $expiry = new class {
            public bool $attempted = false;
        };
        $ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(static function () use ($expiry, $accepted, $pending): TranscodeRuntimeSnapshot {
            return $expiry->attempted ? $accepted : $pending;
        });
        $ownership->expects(self::once())->method('expireAdmission')->with($job->getId(), $session->getId(), $requestId)
            ->willReturnCallback(static function () use ($expiry): bool {
                $expiry->attempted = true;
                return false;
            });
        $ownership->expects(self::never())->method('admit');
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('getOrCreateCopyJob')->willReturn($job);
        $jobs->method('findByUuid')->willReturn($job);
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($session);
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn('/tmp/copy');
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn(callable $action): mixed => $action());
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->expects(self::exactly(50))->method('sleep')->with(0.1);
        $service = new CopyPresentationService(
            new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)),
            $jobs,
            $sessions,
            $storage,
            $ownership,
            $transaction,
            $sleeper,
            $this->freeSessionShare(),
        );
        if (!$succeeds) {
            $this->expectException(TranscodeStartupUnavailableException::class);
        }
        $result = $service->start(new CopyPresentationInput($owner, $video, 'source-hash', 0, 1, 'mp4', 1920, 1080, $requestId));
        self::assertTrue($succeeds);
        self::assertSame($job->getPublicId()->toString(), $result->jobId->toString());
        self::assertSame($session->getPublicId()->toString(), $result->sessionId->toString());
        self::assertSame($requestId, $result->requestId);
    }

    public function testTheUsersLockComesBeforeTheVideoRowAndRoomIsMadeBeforeTheSessionIsCreated(): void
    {
        $input = $this->input();
        [$job, $session] = $this->copyOf($input);
        $share = $this->createMock(TranscodeSessionSharePort::class);
        $share->expects(self::once())->method('lockUser')->willReturnCallback(function (Uuid $user) use ($input): void {
            self::assertTrue($this->insideTransaction);
            self::assertTrue($user->equals($input->owner));
            $this->steps[] = 'user';
        });
        $share->method('heldBy')->willReturnCallback(function (): array {
            $this->steps[] = 'count';

            return [];
        });
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('lockAdmission')->willReturnCallback(function (): void {
            $this->steps[] = 'video';
        });
        $ownership->method('find')->willReturnOnConsecutiveCalls(null, $this->runtime($job, $session, $input, 'accepted'));
        $ownership->method('admit')->willReturnCallback(function () use ($job, $session, $input): TranscodeRuntimeSnapshot {
            $this->steps[] = 'admit';

            return $this->runtime($job, $session, $input, 'pending');
        });
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('createSession')->willReturnCallback(function () use ($session): TranscodeSession {
            $this->steps[] = 'session';

            return $session;
        });

        $presentation = $this->service($share, $ownership, $this->jobs($job), $sessions)->start($input);

        self::assertSame(['user', 'video', 'count', 'session', 'admit'], $this->steps);
        self::assertSame($session->getPublicId()->toString(), $presentation->sessionId->toString());
    }

    public function testACopyStartTheUsersShareCannotMakeRoomForCreatesNoSession(): void
    {
        $input = $this->input();
        [$job] = $this->copyOf($input);
        $share = $this->createStub(TranscodeSessionSharePort::class);
        $share->method('heldBy')->willReturn(array_map(
            static fn (int $index): HeldTranscodeSession => new HeldTranscodeSession(Uuid::generate(), Uuid::generate(), Uuid::generate(), 'other-' . $index, '720p', new \DateTimeImmutable(), true),
            range(1, 6),
        ));
        $ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $ownership->expects(self::never())->method('admit');
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->expects(self::never())->method('createSession');

        $this->expectException(TranscodeSessionLimitException::class);
        $this->service($share, $ownership, $this->jobs($job), $sessions)->start($input);
    }

    /** A session the user's share or a playback control ended may leave its finished copy complete. */
    public function testAnEndedSessionOfTheClientsCopyIsNotHandedBack(): void
    {
        $input = $this->input();
        [$job, $ended] = $this->copyOf($input);
        $ended->markCancelled();
        $replacement = TranscodeSession::create($input->owner, $job->getId(), $input->video, AudioProfile::streamingStereo());
        $finished = $this->runtime($job, $ended, $input, 'complete');
        $ownership = $this->createMock(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnOnConsecutiveCalls($finished, $finished, $this->runtime($job, $replacement, $input, 'accepted'));
        $ownership->expects(self::once())->method('admit')->with($job->getId(), $replacement->getId(), $input->requestId, self::anything())
            ->willReturn($this->runtime($job, $replacement, $input, 'pending'));
        $sessions = $this->createMock(TranscodeSessionPortInterface::class);
        $sessions->method('findByUuid')->willReturn($ended);
        $sessions->expects(self::once())->method('createSession')->willReturn($replacement);

        $presentation = $this->service($this->createStub(TranscodeSessionSharePort::class), $ownership, $this->jobs($job), $sessions)->start($input);

        self::assertSame($replacement->getPublicId()->toString(), $presentation->sessionId->toString());
    }

    private function input(): CopyPresentationInput
    {
        return new CopyPresentationInput(Uuid::generate(), Uuid::generate(), 'source-hash', 0, 1, 'mp4', 1920, 1080, Uuid::generate()->toString());
    }

    /** @return array{TranscodeJob, TranscodeSession} */
    private function copyOf(CopyPresentationInput $input): array
    {
        $selection = new PresentationSelection('copy', $input->sourceHash, $input->videoStreamIndex, $input->audioStreamIndex, $input->container);
        $job = TranscodeJob::createCopy($input->video, '/tmp/copy', $input->owner, $selection, $input->width, $input->height, $input->requestId);

        return [$job, TranscodeSession::create($input->owner, $job->getId(), $input->video, AudioProfile::streamingStereo())];
    }

    private function runtime(TranscodeJob $job, TranscodeSession $session, CopyPresentationInput $input, string $outcome): TranscodeRuntimeSnapshot
    {
        $desired = new DesiredTranscodeState(AudioProfile::streamingStereo());

        return new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), $input->requestId, $desired, 1, 0, 1, $outcome, null, null, in_array($outcome, ['accepted', 'ready'], true));
    }

    private function jobs(TranscodeJob $job): TranscodeJobPortInterface
    {
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('getOrCreateCopyJob')->willReturn($job);
        $jobs->method('findByUuid')->willReturn($job);

        return $jobs;
    }

    private function service(TranscodeSessionSharePort $share, TranscodeExecutionOwnershipPort $ownership, TranscodeJobPortInterface $jobs, TranscodeSessionPortInterface $sessions): CopyPresentationService
    {
        $storage = $this->createStub(TranscodeStoragePortInterface::class);
        $storage->method('resolveJobDirectory')->willReturn('/tmp/copy');
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->insideTransaction = true;
            try {
                return $operation();
            } finally {
                $this->insideTransaction = false;
            }
        });

        return new CopyPresentationService(
            new MediaExecutionPolicy(new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)),
            $jobs,
            $sessions,
            $storage,
            $ownership,
            $transaction,
            $this->createStub(SleeperInterface::class),
            $this->freeSessionShare($share),
        );
    }
}
