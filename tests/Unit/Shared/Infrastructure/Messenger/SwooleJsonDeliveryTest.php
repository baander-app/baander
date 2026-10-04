<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Entity\JobMonitorEntity;
use App\Shared\Infrastructure\Messenger\JobIdStamp;
use App\Shared\Infrastructure\Messenger\JobMessageSerializer;
use App\Shared\Infrastructure\Messenger\JobMonitorService;
use App\Shared\Infrastructure\Messenger\SwooleTaskJobMonitorDecorator;
use App\Shared\Infrastructure\Pagination\CursorPaginator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use App\Shared\Infrastructure\Messaging\JsonMessageCodec;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger\SwooleServerTaskTransportHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SwooleJsonDeliveryTest extends TestCase
{
    public function testDecodedTaskIsHandledOnceWithoutSendingItBackToItsQueue(): void
    {
        $command = new ExtractAlbumCoverCommand(Uuid::v4());
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->never())->method('send');
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($sender);
        $container->method('has')->willReturn(true);
        $handled = [];
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([ExtractAlbumCoverCommand::class => ['swoole_task']], $container)),
            new HandleMessageMiddleware(new HandlersLocator([ExtractAlbumCoverCommand::class => [static function (ExtractAlbumCoverCommand $message) use (&$handled): void { $handled[] = $message; }]])),
        ]);
        $serializer = new JsonTransportSerializer(new JsonMessageCodec());
        $task = new \Swoole\Server\Task();
        $task->data = $serializer->encode(new Envelope($command));
        $server = new \Swoole\Server('127.0.0.1', 0);
        (new SwooleServerTaskTransportHandler($bus, $serializer))->handle($server, $task);
        self::assertEquals([$command], $handled);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function monitoredExecutions(): iterable
    {
        yield 'retry handled' => [true, false];
        yield 'retry failed' => [true, true];
        yield 'unstamped handled' => [false, false];
        yield 'unstamped failed' => [false, true];
    }

    #[DataProvider('monitoredExecutions')]
    public function testEncodedTaskRetainsRetryIdOrGeneratesMonitorId(bool $suppliedId, bool $fails): void
    {
        $jobId = new PublicId();
        $command = new ExtractAlbumCoverCommand(Uuid::generate());
        $envelope = new Envelope($command);

        if ($suppliedId) {
            $envelope = $envelope->with(new JobIdStamp($jobId));
        }

        $created = null;
        $updates = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('update')->willReturnCallback(
            /**
             * @param array<string, mixed> $data
             * @param array<string, mixed> $criteria
             */
            static function (string $table, array $data, array $criteria) use (&$updates): int {
                self::assertSame('job_monitors', $table);
                $updates[] = ['data' => $data, 'criteria' => $criteria];

                return 1;
            },
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$created): void {
            self::assertInstanceOf(JobMonitorEntity::class, $entity);
            $created = $entity;
        });

        $handled = [];
        $failure = new \RuntimeException('Task handler failed.');
        $bus = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                ExtractAlbumCoverCommand::class => [static function (ExtractAlbumCoverCommand $message) use (&$handled, $fails, $failure): void {
                    $handled[] = $message;

                    if ($fails) {
                        throw $failure;
                    }
                }],
            ])),
        ]);
        $serializer = new JsonTransportSerializer(new JsonMessageCodec());
        $task = new \Swoole\Server\Task();
        $task->data = $serializer->encode($envelope);
        $server = new \Swoole\Server('127.0.0.1', 0);
        $decorator = new SwooleTaskJobMonitorDecorator(
            new SwooleServerTaskTransportHandler($bus, $serializer),
            new JobMonitorService($em, new CursorPaginator(), new JsonEncoder()),
            new JobMessageSerializer(new JsonMessageCodec()),
            new NullLogger(),
            $serializer,
        );
        $caught = null;

        try {
            $decorator->handle($server, $task);
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $exception) {
            $caught = $exception;
        }

        self::assertEquals([$command], $handled);
        self::assertSame($fails, $caught !== null);
        self::assertInstanceOf(JobMonitorEntity::class, $created);
        self::assertSame('swoole_task', $created->getQueue());
        $monitorId = $created->getJobId();
        self::assertSame($monitorId, PublicId::fromString($monitorId)->toString());

        if ($suppliedId) {
            self::assertSame($jobId->toString(), $monitorId);
        } else {
            self::assertNotSame($jobId->toString(), $monitorId);
        }

        self::assertSame((new JsonMessageCodec())->encode($command), $updates[0]['data']['data']);
        self::assertFalse($updates[0]['data']['data_truncated']);
        self::assertSame(JobStatus::Running->value, $updates[1]['data']['status']);
        self::assertSame(($fails ? JobStatus::Failed : JobStatus::Finished)->value, $updates[2]['data']['status']);

        foreach ($updates as $update) {
            self::assertSame(['job_id' => $monitorId], $update['criteria']);
        }
    }

}
