<?php

declare(strict_types=1);

namespace App\Tests\Unit\Transcode\Interface\Controller;

use App\Transcode\Application\Command\CleanupOrphanedJobsCommand;
use App\Transcode\Application\Exception\TranscodeCleanupIncompleteException;
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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Translation\Translator;

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
        $owner = Uuid::generate();
        $job = TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/transcode', $owner);
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
        $controller = $this->controller($jobs, $view, $playback);
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }

        $response = $controller->$method($job->getPublicId()->toString(), self::user($owner));

        self::assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        self::assertStringContainsString($videoPublicId->toString(), $content);
        self::assertStringNotContainsString($job->getVideoId()->toString(), $content);
    }

    /** @return iterable<string, array{string}> */
    public static function individualJobReads(): iterable
    {
        yield 'show' => ['show'];
        yield 'getMetrics' => ['getMetrics'];
    }

    #[DataProvider('individualJobReads')]
    public function testAnotherUsersJobIsNotFoundForAMemberAsAMissingOneIs(string $method): void
    {
        $job = TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/transcode', Uuid::generate());
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByPublicId')->willReturnCallback(
            static fn (PublicId $id): ?TranscodeJob => $id->equals($job->getPublicId()) ? $job : null,
        );
        $controller = $this->controller($jobs, new \App\Tests\Unit\Transcode\PublicViewStub(), $this->createStub(PlaybackAccessInterface::class));
        $member = self::user(Uuid::generate());

        $foreign = $controller->$method($job->getPublicId()->toString(), $member);
        $missing = $controller->$method((new PublicId())->toString(), $member);

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame($missing->getContent(), $foreign->getContent());
    }

    #[DataProvider('individualJobReads')]
    public function testAnAdministratorReadsAnotherUsersJob(string $method): void
    {
        $job = TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/transcode', Uuid::generate());
        $jobs = $this->createStub(TranscodeJobPortInterface::class);
        $jobs->method('findByPublicId')->willReturn($job);
        $controller = $this->controller($jobs, new \App\Tests\Unit\Transcode\PublicViewStub(), $this->createStub(PlaybackAccessInterface::class), admin: true);

        $response = $controller->$method($job->getPublicId()->toString(), self::user(Uuid::generate()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            $job->getPublicId()->toString(),
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']['publicId'],
        );
    }

    /** @return iterable<string, array{bool, list<string>}> whether the caller is an administrator, and the jobs they see */
    public static function orphanReaders(): iterable
    {
        yield 'a member sees only their own jobs' => [false, ['own']];
        yield 'an administrator sees every job' => [true, ['own', 'foreign']];
    }

    /** @param list<string> $visible */
    #[DataProvider('orphanReaders')]
    public function testTheOrphanCollectionShowsAMemberOnlyTheirOwnJobs(bool $admin, array $visible): void
    {
        $caller = Uuid::generate();
        $jobs = [
            'own' => TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/own', $caller),
            'foreign' => TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/foreign', Uuid::generate()),
        ];
        $port = $this->createStub(TranscodeJobPortInterface::class);
        $port->method('findOrphanedJobs')->willReturn(array_values($jobs));
        $controller = $this->controller($port, new \App\Tests\Unit\Transcode\PublicViewStub(), $this->createStub(PlaybackAccessInterface::class), $admin);

        $result = json_decode($controller->index(self::user($caller))->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            array_map(static fn (string $name): string => $jobs[$name]->getPublicId()->toString(), $visible),
            array_column($result['data'], 'publicId'),
        );
    }

    public function testOrphanCollectionFiltersInaccessibleJobsBeforeProjectionAndPreservesOrder(): void
    {
        $owner = Uuid::generate();
        $jobs = [
            TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/first', $owner),
            TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/denied', $owner),
            TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/last', $owner),
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
        $controller = $this->controller($port, $view, $playback);

        $result = json_decode($controller->index(self::user($owner))->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([$jobs[0]->getPublicId()->toString(), $jobs[2]->getPublicId()->toString()], array_column($result['data'], 'publicId'));
        self::assertSame([0, 1], array_keys($result['data']));
        self::assertStringNotContainsString($jobs[1]->getPublicId()->toString(), json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testUserJobHistoryRechecksCurrentLibraryAccess(): void
    {
        $owner = new Uuid();
        $job = TranscodeJob::create(new Uuid(), QualityTier::p720(), '/tmp/revoked', \App\Shared\Domain\Model\Uuid::generate());
        $videoPublicId = new PublicId();
        $query = $this->createMock(TranscodeJobQueryPort::class);
        $query->expects(self::once())->method('findByUser')->with($owner)->willReturn([TranscodeJobDto::fromModel($job, $videoPublicId)]);
        $view = $this->createMock(TranscodePublicViewInterface::class);
        $view->expects(self::once())->method('videoId')->with($videoPublicId)->willReturn($job->getVideoId());
        $playback = $this->createMock(PlaybackAccessInterface::class);
        $playback->expects(self::once())->method('assertAccess')->with($job->getVideoId())->willThrowException(new AccessDeniedException());
        $controller = $this->controller($this->createStub(TranscodeJobPortInterface::class), $view, $playback);

        $response = $controller->listJobs($query, self::user($owner));

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
            ->willReturn(new Envelope(new CleanupOrphanedJobsCommand(), [new HandledStamp(new \App\Transcode\Application\DTO\TranscodeCleanupResult(expired: 2, cleaned: 3), 'cleanup')]));
        $controller = $this->cleanupController($bus);

        self::assertSame(['data' => ['expired' => 2, 'cleaned' => 3]], json_decode($controller->cleanup()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testCleanupWithoutItsResultIsAFaultNotAnEmptyCount(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new CleanupOrphanedJobsCommand(), [new HandledStamp(['expired' => 2, 'cleaned' => 3], 'cleanup')]));
        $controller = $this->cleanupController($bus);

        $this->expectException(\LogicException::class);
        $controller->cleanup();
    }

    public function testACleanupThatLeftJobsBehindAnswersWithItsCountsAsAnError(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new HandlerFailedException(
            new Envelope(new CleanupOrphanedJobsCommand()),
            [new TranscodeCleanupIncompleteException(removed: 6, failed: 2, expired: 4)],
        ));
        $controller = $this->cleanupController($bus);

        // The controller holds no answer of its own for this outcome: the exception subscriber every
        // endpoint shares unwraps the handler's failure and answers it.
        $events = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $events->addListener(\Symfony\Component\HttpKernel\KernelEvents::EXCEPTION, new \App\Shared\Infrastructure\EventListener\ExceptionSubscriber(
            new \Psr\Log\NullLogger(),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
        ));
        $controllers = $this->createStub(\Symfony\Component\HttpKernel\Controller\ControllerResolverInterface::class);
        $controllers->method('getController')->willReturn($controller->cleanup(...));
        $arguments = $this->createStub(\Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface::class);
        $arguments->method('getArguments')->willReturn([]);

        $response = (new \Symfony\Component\HttpKernel\HttpKernel($events, $controllers, new \Symfony\Component\HttpFoundation\RequestStack(), $arguments))
            ->handle(\Symfony\Component\HttpFoundation\Request::create('/api/transcode/jobs/cleanup', 'POST'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => [
            'message' => 'The transcode job cleanup removed 6 orphaned jobs and could not remove 2; the log names each job that failed.',
            'code' => 503,
            'details' => ['expired' => 4, 'cleaned' => 6, 'failed' => 2],
        ]], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAnyOtherCleanupFailureIsLeftToTheErrorHandler(): void
    {
        $failure = new HandlerFailedException(new Envelope(new CleanupOrphanedJobsCommand()), [new \RuntimeException('The orphaned jobs could not be read.')]);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException($failure);
        $controller = $this->cleanupController($bus);

        try {
            $controller->cleanup();
            self::fail('A cleanup that failed outright has no answer of its own.');
        } catch (HandlerFailedException $thrown) {
            self::assertSame($failure, $thrown);
        }
    }

    /** The controller, with a caller who is an administrator only when the test says so. */
    private function controller(
        TranscodeJobPortInterface $jobs,
        TranscodePublicViewInterface $view,
        PlaybackAccessInterface $playback,
        bool $admin = false,
        ?MessageBusInterface $bus = null,
    ): TranscodeJobController {
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $admin && $attribute === 'ROLE_ADMIN',
        );

        $controller = new TranscodeJobController($bus ?? $this->createStub(MessageBusInterface::class), $jobs, $view, $playback, $authorization);
        $controller->setTranslator(new Translator('en'));

        return $controller;
    }

    private function cleanupController(MessageBusInterface $bus): TranscodeJobController
    {
        return $this->controller(
            $this->createStub(TranscodeJobPortInterface::class),
            new \App\Tests\Unit\Transcode\PublicViewStub(),
            $this->createStub(PlaybackAccessInterface::class),
            admin: true,
            bus: $bus,
        );
    }

    private static function user(Uuid $id): SecurityUser
    {
        return new SecurityUser($id->toString(), 'viewer@baander.app', '');
    }
}
