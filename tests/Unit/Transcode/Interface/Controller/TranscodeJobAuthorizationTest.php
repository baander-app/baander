<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\Port\TranscodeJobPortInterface;
use App\Transcode\Application\Port\PlaybackAccessInterface;
use App\Transcode\Application\Port\TranscodePublicViewInterface;
use App\Transcode\Application\DTO\TranscodeJobDto;
use App\Transcode\Application\Query\TranscodeJobQueryPort;
use App\Transcode\Domain\Model\TranscodeJob;
use App\Transcode\Domain\ValueObject\QualityTier;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Transcode\Interface\Controller\TranscodeJobController;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TranscodeJobAuthorizationTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function jobReads(): iterable
    {
        foreach (['show', 'getMetrics'] as $method) {
            yield $method . ' allowed' => [$method, true];
            yield $method . ' denied' => [$method, false];
        }
    }

    #[DataProvider('jobReads')]
    public function testIndividualJobReadsCheckLibraryAccessBeforePublicProjection(string $method, bool $allowed): void
    {
        $job = TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/transcode');
        $jobs = $this->createMock(TranscodeJobPortInterface::class);
        $jobs->expects(self::once())->method('findByPublicId')->with($job->getPublicId())->willReturn($job);
        $playback = $this->createMock(PlaybackAccessInterface::class);
        $expectation = $playback->expects(self::once())->method('assertAccess')->with($job->getVideoId());
        if (!$allowed) {
            $expectation->willThrowException(new AccessDeniedException('Video is not accessible.'));
        }
        $videoPublicId = new PublicId();
        $view = $this->createMock(TranscodePublicViewInterface::class);
        $view->expects($allowed ? self::once() : self::never())->method('job')->with($job)
            ->willReturn(TranscodeJobDto::fromModel($job, $videoPublicId));
        $controller = new TranscodeJobController($this->createStub(MessageBusInterface::class), $jobs, $view, $playback);
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }

        $response = $controller->$method($job->getPublicId()->toString());

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString($videoPublicId->toString(), $content);
        self::assertStringNotContainsString($job->getVideoId()->toString(), $content);
    }

    public function testOrphanCollectionFiltersInaccessibleJobsBeforeProjectionAndPreservesOrder(): void
    {
        $jobs = [
            TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/first'),
            TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/denied'),
            TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/last'),
        ];
        $port = $this->createMock(TranscodeJobPortInterface::class);
        $port->expects(self::once())->method('findOrphanedJobs')->willReturn($jobs);
        $playback = $this->createMock(PlaybackAccessInterface::class);
        $playback->expects(self::exactly(3))->method('assertAccess')->willReturnCallback(static function (Uuid $video) use ($jobs): void {
            if ($video->equals($jobs[1]->getVideoId())) {
                throw new AccessDeniedException('Video is not accessible.');
            }
        });
        $view = $this->createMock(TranscodePublicViewInterface::class);
        $view->expects(self::exactly(2))->method('job')->willReturnCallback(static function (TranscodeJob $job) use ($jobs): TranscodeJobDto {
            self::assertNotSame($jobs[1], $job);

            return TranscodeJobDto::fromModel($job, new PublicId());
        });
        $controller = new TranscodeJobController($this->createStub(MessageBusInterface::class), $port, $view, $playback);

        $result = json_decode($controller->index()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([$jobs[0]->getPublicId()->toString(), $jobs[2]->getPublicId()->toString()], array_column($result['data'], 'publicId'));
        self::assertSame([0, 1], array_keys($result['data']));
        self::assertStringNotContainsString($jobs[1]->getPublicId()->toString(), json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUserJobHistoryRechecksCurrentLibraryAccess(): void
    {
        $owner = new Uuid();
        $job = TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/revoked');
        $videoPublicId = new PublicId();
        $query = $this->createMock(TranscodeJobQueryPort::class);
        $query->expects(self::once())->method('findByUser')->with($owner)->willReturn([TranscodeJobDto::fromModel($job, $videoPublicId)]);
        $view = $this->createMock(TranscodePublicViewInterface::class);
        $view->expects(self::once())->method('videoId')->with($videoPublicId)->willReturn($job->getVideoId());
        $playback = $this->createMock(PlaybackAccessInterface::class);
        $playback->expects(self::once())->method('assertAccess')->with($job->getVideoId())->willThrowException(new AccessDeniedException());
        $controller = new TranscodeJobController($this->createStub(MessageBusInterface::class), $this->createStub(TranscodeJobPortInterface::class), $view, $playback);

        $response = $controller->listJobs($query, new SecurityUser($owner->toString(), 'viewer@baander.app', ''));

        self::assertSame(['data' => []], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testCleanupIsGrantedOnlyToAdministrators(): void
    {
        // The security listener denies the request before the action runs; the
        // admin/CLI parity test reads the same attribute to classify the route.
        $grants = array_map(
            static fn (\ReflectionAttribute $attribute): IsGranted => $attribute->newInstance(),
            (new \ReflectionMethod(TranscodeJobController::class, 'cleanup'))->getAttributes(IsGranted::class),
        );

        self::assertCount(1, $grants);
        self::assertSame('ROLE_ADMIN', $grants[0]->attribute);
        self::assertSame([], $grants[0]->methods);
    }

    public function testAdministratorCanCleanup(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->with($this->isInstanceOf(CleanupOrphanedJobsCommand::class))
            ->willReturn(new Envelope(new CleanupOrphanedJobsCommand(), [new HandledStamp(3, 'cleanup')]));
        $controller = new TranscodeJobController($bus, $this->createStub(TranscodeJobPortInterface::class), new \App\Tests\Unit\Transcode\PublicViewStub(), $this->createStub(\App\Transcode\Application\Port\PlaybackAccessInterface::class));

        self::assertSame(['data' => ['cleaned' => 3]], json_decode($controller->cleanup()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
