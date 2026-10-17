<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Infrastructure\Transcode;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Application\Port\TranscodeStoragePortInterface;
use App\Transcode\Application\Port\TranscodeExecutionOwnershipPort;
use App\Transcode\Application\Port\TranscodeSessionPortInterface;
use App\Transcode\Application\Command\CreateTranscodeSessionCommand;
use App\Transcode\Application\CommandHandler\CreateTranscodeSessionHandler;
use App\Transcode\Application\DTO\TranscodeRuntimeSnapshot;
use App\Transcode\Application\DTO\DesiredTranscodeState;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Shared\Application\Port\SleeperInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\Repository\TranscodeJobRepositoryInterface;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Transcode\Infrastructure\Transcode\TranscodeJobService;
use App\Transcode\Infrastructure\Storage\TranscodeFileStorage;
use App\Transcode\Infrastructure\Storage\SegmentFileResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class OwnedTranscodeJobTest extends TestCase
{
    public function testTwoUsersAdmitIndependentJobsForTheSameVideoAndTier(): void
    {
        $repository = new OwnedJobRepository();
        $service = new TranscodeJobService($repository, new TranscodeFileStorage(new SegmentFileResolver('/cache')), new EventDispatcher());
        $video = Uuid::generate();
        $alice = Uuid::generate();
        $bob = Uuid::generate();
        $first = $service->getOrCreateJob($video, QualityTier::p720(), '/cache', $alice);
        $second = $service->getOrCreateJob($video, QualityTier::p720(), '/cache', $bob);
        $again = $service->getOrCreateJob($video, QualityTier::p720(), '/cache', $alice);
        self::assertFalse($first->getId()->equals($second->getId()));
        self::assertNotNull($first->getOwnerUserId());
        self::assertNotNull($second->getOwnerUserId());
        self::assertTrue($first->getOwnerUserId()->equals($alice));
        self::assertTrue($second->getOwnerUserId()->equals($bob));
        self::assertNotSame($first->getOutputDirectory(), $second->getOutputDirectory());
        self::assertStringEndsWith('/job-' . $first->getId()->toString(), $first->getOutputDirectory());
        self::assertSame($first, $again);
        self::assertCount(2, $repository->jobs);
        $first->attachSession();
        $first->markInProgress();
        $first->markCancelled();
        self::assertSame('pending', $second->getStatus()->value);
        self::assertSame(0, $second->getReferenceCount());
    }
    public function testAdmissionReturnsEachUsersOwnedSessionAndReusesOnlyItsOwn(): void
    {
        $repository = new OwnedJobRepository();
        $storage = new TranscodeFileStorage(new SegmentFileResolver('/cache'));
        $jobs = new TranscodeJobService($repository, $storage, new EventDispatcher());
        $rows = new OwnedAdmissionRows();
        $sessions = $this->createStub(TranscodeSessionPortInterface::class);
        $sessions->method('createSession')->willReturnCallback(static function (Uuid $user, Uuid $job, Uuid $video, AudioProfile $profile) use ($rows): TranscodeSession {
            $session = TranscodeSession::create($user, $job, $video, $profile);
            $rows->sessions[$session->getId()->toString()] = $session;
            return $session;
        });
        $sessions->method('findByUuid')->willReturnCallback(static function (Uuid $id) use ($rows): ?TranscodeSession { return $rows->sessions[$id->toString()] ?? null; });
        $ownership = $this->createStub(TranscodeExecutionOwnershipPort::class);
        $ownership->method('find')->willReturnCallback(static function (Uuid $job) use ($rows): ?TranscodeRuntimeSnapshot { return $rows->runtimes[$job->toString()] ?? null; });
        $ownership->method('admit')->willReturnCallback(static function (Uuid $job, Uuid $session, string $request, DesiredTranscodeState $desired) use ($rows): TranscodeRuntimeSnapshot {
            return $rows->runtimes[$job->toString()] = new TranscodeRuntimeSnapshot($job, $session, $request, $desired, 1, 1, 1, 'accepted', null, null, true);
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());
        $handler = new CreateTranscodeSessionHandler($jobs, $sessions, $storage, $ownership, $transaction, $this->createStub(SleeperInterface::class));
        $video = Uuid::generate();
        $alice = Uuid::generate();
        $bob = Uuid::generate();
        $first = $handler(new CreateTranscodeSessionCommand($alice, $video, QualityTier::p720(), AudioProfile::streamingStereo()));
        $second = $handler(new CreateTranscodeSessionCommand($bob, $video, QualityTier::p720(), AudioProfile::streamingStereo()));
        $again = $handler(new CreateTranscodeSessionCommand($alice, $video, QualityTier::p720(), AudioProfile::streamingStereo()));
        self::assertTrue($first->getUserId()->equals($alice));
        self::assertTrue($second->getUserId()->equals($bob));
        self::assertFalse($first->getJobId()->equals($second->getJobId()));
        self::assertSame($first, $again);
        self::assertCount(2, $rows->sessions);
        self::assertCount(2, $repository->jobs);
    }

}

final class OwnedJobRepository implements TranscodeJobRepositoryInterface
{
    /** @var array<string, TranscodeJob> */
    public array $jobs = [];
    public function save(TranscodeJob $job): void { $this->jobs[$job->getId()->toString()] = $job; }
    public function persist(TranscodeJob $job): void { $this->save($job); }
    public function flush(): void {}
    public function findByUuid(Uuid $uuid): ?TranscodeJob { return $this->jobs[$uuid->toString()] ?? null; }
    public function findByPublicId(PublicId $publicId): ?TranscodeJob { return null; }
    public function findByOwnerVideoAndQuality(Uuid $ownerUserId, Uuid $videoId, string $qualityTierName): ?TranscodeJob
    {
        foreach ($this->jobs as $job) {
            if ($job->getOwnerUserId()?->equals($ownerUserId) && $job->getVideoId()->equals($videoId) && $job->getQualityTierName() === $qualityTierName) {
                return $job;
            }
        }
        return null;
    }
    public function findActiveByOwnerAndVideo(Uuid $ownerUserId, Uuid $videoId): array { return []; }
    public function findActiveByVideo(Uuid $videoId): array { return []; }
    public function findOrphanedJobs(): array { return []; }
    public function findInProgressJobs(): array { return []; }
    public function delete(TranscodeJob $job): void { unset($this->jobs[$job->getId()->toString()]); }
}

final class OwnedAdmissionRows
{
    /** @var array<string, TranscodeSession> */
    public array $sessions = [];
    /** @var array<string, TranscodeRuntimeSnapshot> */
    public array $runtimes = [];
}
