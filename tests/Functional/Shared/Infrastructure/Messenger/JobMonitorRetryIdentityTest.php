<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use App\Shared\Infrastructure\Messenger\WorkerJobMonitorSubscriber;
use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Tests\Functional\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * A redelivered message keeps its job ID, so every attempt of one job (a Messenger retry,
 * a retry from the failure transport, a redelivery after a worker died) restarts the same
 * job_monitors row, and only the current attempt completes it.
 */
final class JobMonitorRetryIdentityTest extends TestCase
{
    private const string FIRST_ATTEMPT_ERROR = 'First attempt failed.';

    private int $attempts = 0;

    /** @var list<array<string, mixed>>|null job_monitors rows seen while the second attempt runs */
    private ?array $rowsDuringRetry = null;

    private ?string $jobId = null;

    public function testMessengerRetryRestartsTheSameJobRow(): void
    {
        $async = $this->transport();
        $async->send(new Envelope(new ExtractAlbumCoverCommand(Uuid::generate())));
        $retry = new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $async]),
            new ServiceLocator(['async' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(3, 0, jitter: 0)]),
        );

        $this->runWorker(['async' => $async], $retry);
        $this->assertFirstAttemptFailed();
        $this->moveFirstAttemptIntoThePast();
        $this->runWorker(['async' => $async], $retry);

        $this->assertSecondAttemptReusedTheRow('async');
    }

    public function testFailureTransportRetryRestartsTheSameJobRow(): void
    {
        $async = $this->transport();
        $failed = $this->transport();
        $async->send(new Envelope(new ExtractAlbumCoverCommand(Uuid::generate())));
        $noRetry = new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $async]),
            new ServiceLocator(['async' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(0)]),
        );
        $toFailureTransport = new SendFailedMessageToFailureTransportListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $failed]),
        );

        $this->runWorker(['async' => $async], $noRetry, $toFailureTransport);
        $this->assertFirstAttemptFailed();
        self::assertCount(1, $failed->getSent());
        $this->moveFirstAttemptIntoThePast();
        // messenger:failed:retry runs a worker whose receiver is the failure transport.
        $this->runWorker(['failed' => $failed]);

        $this->assertSecondAttemptReusedTheRow('async');
    }

    public function testRedeliveryAfterAWorkerDiedRestartsTheSameJobRow(): void
    {
        // The bus assigns the job ID before the transport stores the message.
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new ExtractAlbumCoverCommand(Uuid::generate()));
        $sent = static::getContainer()->get('messenger.transport.async')->getSent();
        self::assertCount(1, $sent);
        $this->jobId = $sent[0]->last(JobIdStamp::class)?->jobId->toString();
        self::assertNotNull($this->jobId);

        // The first worker starts the job and dies; the transport later redelivers the message.
        static::getContainer()->get(WorkerJobMonitorSubscriber::class)->onMessageReceived(new WorkerMessageReceivedEvent($sent[0], 'async'));
        $this->moveFirstAttemptIntoThePast();
        $async = $this->transport();
        $async->send($sent[0]);
        $this->attempts = 1;
        $this->runWorker(['async' => $async]);

        $this->assertSecondAttemptReusedTheRow('async');
    }

    public function testAStaleAttemptDoesNotCompleteTheCurrentOne(): void
    {
        $service = static::getContainer()->get(JobMonitorService::class);
        self::assertSame(1, $service->startAttempt('stale-attempt-job', 'ExtractAlbumCoverCommand', 'async', null, true));
        self::assertSame(2, $service->startAttempt('stale-attempt-job', 'ExtractAlbumCoverCommand', 'failed', null, true));

        $service->markFinished('stale-attempt-job', 1);
        self::assertSame(
            ['status' => 'running', 'finished_at' => null, 'attempt' => 2, 'queue' => 'async'],
            $this->entityManager->getConnection()->fetchAssociative(
                "SELECT status, finished_at, attempt, queue FROM job_monitors WHERE job_id = 'stale-attempt-job'",
            ),
        );

        $service->markFailed('stale-attempt-job', 2, new \RuntimeException(self::FIRST_ATTEMPT_ERROR));
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT status, attempt, exception_class, duration_microseconds FROM job_monitors WHERE job_id = 'stale-attempt-job'",
        );
        self::assertSame(['failed', 2, \RuntimeException::class], [$row['status'], $row['attempt'], $row['exception_class']]);
        self::assertGreaterThanOrEqual(0, $row['duration_microseconds']);
    }

    private function transport(): InMemoryTransport
    {
        // Encode through the transport contract, so the job ID survives as a real redelivery would.
        return new InMemoryTransport(new JsonTransportSerializer(MessageCodecFactory::create()));
    }

    /** @param array<string, InMemoryTransport> $receivers */
    private function runWorker(array $receivers, object ...$listeners): void
    {
        $subscriber = static::getContainer()->get(WorkerJobMonitorSubscriber::class);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, $subscriber->onMessageReceived(...));
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $subscriber->onMessageHandled(...));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $subscriber->onMessageFailed(...));
        foreach ($listeners as $listener) {
            $dispatcher->addSubscriber($listener);
        }

        $connection = $this->entityManager->getConnection();
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ExtractAlbumCoverCommand::class => [function () use ($connection): void {
                if (++$this->attempts === 1) {
                    throw new \RuntimeException(self::FIRST_ATTEMPT_ERROR);
                }
                $this->rowsDuringRetry = $connection->fetchAllAssociative(
                    'SELECT status, started_at, finished_at, duration_microseconds, attempt FROM job_monitors WHERE job_id = ?',
                    [$this->jobId],
                );
            }],
        ]))]);

        $worker = new Worker($receivers, $bus, $dispatcher);
        $stop = static function () use ($worker): void {
            $worker->stop();
        };
        $dispatcher->addListener(WorkerMessageHandledEvent::class, $stop, -1000);
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $stop, -1000);
        $worker->run(['sleep' => 0]);
    }

    private function assertFirstAttemptFailed(): void
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT job_id, status, exception_class FROM job_monitors WHERE name = 'ExtractAlbumCoverCommand'",
        );
        self::assertCount(1, $rows);
        self::assertSame('failed', $rows[0]['status']);
        self::assertNotNull($rows[0]['exception_class']);
        $this->jobId = $rows[0]['job_id'];
    }

    /** A retry starts after a delay; make that delay visible at the columns' second precision. */
    private function moveFirstAttemptIntoThePast(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE job_monitors SET created_at = created_at - INTERVAL '1 minute', queued_at = queued_at - INTERVAL '1 minute',
                started_at = started_at - INTERVAL '1 minute', finished_at = finished_at - INTERVAL '1 minute'
             WHERE job_id = ?",
            [$this->jobId],
        );
    }

    private function assertSecondAttemptReusedTheRow(string $queue): void
    {
        self::assertSame(2, $this->attempts);

        // While the retry runs, its single row is running and has no finish time or duration.
        self::assertNotNull($this->rowsDuringRetry);
        self::assertSame(['rows' => 1, 'negative durations' => 0], [
            'rows' => count($this->rowsDuringRetry),
            'negative durations' => count(array_filter(
                $this->rowsDuringRetry,
                static fn (array $row): bool => $row['duration_microseconds'] !== null && (int) $row['duration_microseconds'] < 0,
            )),
        ]);
        self::assertSame('running', $this->rowsDuringRetry[0]['status']);
        self::assertNull($this->rowsDuringRetry[0]['finished_at']);
        self::assertNull($this->rowsDuringRetry[0]['duration_microseconds']);
        self::assertSame(2, (int) $this->rowsDuringRetry[0]['attempt']);

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT job_id, queue, status, attempt, exception, exception_class, duration_microseconds,
                    started_at > created_at + INTERVAL '30 seconds' AS restarted
             FROM job_monitors WHERE name = 'ExtractAlbumCoverCommand'",
        );
        self::assertCount(1, $rows);
        self::assertSame($this->jobId, $rows[0]['job_id']);
        self::assertSame($queue, $rows[0]['queue']);
        self::assertSame('finished', $rows[0]['status']);
        self::assertSame(2, (int) $rows[0]['attempt']);
        self::assertNull($rows[0]['exception']);
        self::assertNull($rows[0]['exception_class']);
        self::assertGreaterThanOrEqual(0, (int) $rows[0]['duration_microseconds']);
        self::assertLessThan(60_000_000, (int) $rows[0]['duration_microseconds'], 'The duration covers the second attempt only.');
        self::assertTrue((bool) $rows[0]['restarted'], 'The row keeps its creation time and records the new start.');
    }
}
