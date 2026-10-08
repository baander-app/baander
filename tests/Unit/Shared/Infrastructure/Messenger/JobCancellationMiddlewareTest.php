<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Application\JobCancelledException;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Messenger\JobCancellationMiddleware;
use App\Shared\Infrastructure\Messenger\JobCancelledStamp;
use App\Shared\Infrastructure\Messenger\JobExecutionContext;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

final class JobCancellationMiddlewareTest extends TestCase
{
    private JobExecutionContext $context;

    /** @var list<array<string, mixed>> parameters of the job_monitors updates */
    private array $updates = [];

    /** @var list<string|null> the current job each handler call saw */
    private array $seenJobIds = [];

    protected function setUp(): void
    {
        $this->context = new JobExecutionContext();
    }

    public function testAReceivedJobCancelledAtACheckpointEndsAsCancelledWithoutAnError(): void
    {
        $jobId = new PublicId();

        $handled = $this->bus(new \RuntimeException('unused'), cancel: true)
            ->dispatch(new \stdClass(), [new JobIdStamp($jobId), new ReceivedStamp('async')]);

        self::assertNotNull($handled->last(JobCancelledStamp::class));
        self::assertSame([$jobId->toString()], $this->seenJobIds, 'The handler ran as the received job.');
        self::assertCount(1, $this->updates);
        self::assertSame($jobId->toString(), $this->updates[0]['job_id']);
        self::assertSame(JobStatus::Cancelled->value, $this->updates[0]['status']);
        self::assertSame(JobStatus::Running->value, $this->updates[0]['running']);
        self::assertNull($this->context->currentJobId());
    }

    public function testAnyOtherHandlerFailureStillFailsTheJob(): void
    {
        $failure = new \RuntimeException('Disk unavailable.');

        try {
            $this->bus($failure, cancel: false)->dispatch(new \stdClass(), [new JobIdStamp(new PublicId()), new ReceivedStamp('async')]);
            self::fail('A handler failure must reach the transport.');
        } catch (HandlerFailedException $exception) {
            self::assertSame([$failure], array_values($exception->getWrappedExceptions()));
        }

        self::assertSame([], $this->updates);
        self::assertNull($this->context->currentJobId());
    }

    public function testADispatchThatIsNotAReceivedJobHasNoCurrentJob(): void
    {
        $this->bus(null, cancel: false)->dispatch(new \stdClass(), [new JobIdStamp(new PublicId())]);

        self::assertSame([null], $this->seenJobIds);
    }

    private function bus(?\Throwable $failure, bool $cancel): MessageBus
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            function (string $sql, array $params): int {
                $this->updates[] = $params;

                return 1;
            },
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return new MessageBus([
            new JobCancellationMiddleware(
                $this->context,
                new JobMonitorService($entityManager, new CursorPaginator(), new JsonEncoder()),
                new NullLogger(),
            ),
            new HandleMessageMiddleware(new HandlersLocator([
                \stdClass::class => [function () use ($failure, $cancel): void {
                    $jobId = $this->context->currentJobId();
                    $this->seenJobIds[] = $jobId;
                    if ($cancel && $jobId !== null) {
                        throw JobCancelledException::forJob($jobId);
                    }
                    if ($failure !== null && !$cancel) {
                        throw $failure;
                    }
                }],
            ])),
        ]);
    }
}
