<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Application\Service;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\DTO\CopyPresentationInput;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\Exception\TranscodeStartupUnavailableException;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
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
        $requestId = (new Uuid())->toString();
        $selection = new PresentationSelection('copy', 'source-hash', 0, 1, 'mp4');
        $job = TranscodeJob::createCopy($video, '/tmp/copy', $owner, $selection, 1920, 1080, $requestId);
        $session = TranscodeSession::create($owner, $job->getId(), $video, AudioProfile::streamingStereo());
        $desired = new DesiredTranscodeState(AudioProfile::streamingStereo(), presentationSelection: $selection);
        $pending = new TranscodeRuntimeSnapshot($job->getId(), $session->getId(), $requestId, $desired, 1, 0, 1, 'pending', null, null);
        $accepted = new TranscodeRuntimeSnapshot(
            $job->getId(),
            $sameSession ? $session->getId() : new Uuid(),
            $sameRequest ? $requestId : (new Uuid())->toString(),
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
}
