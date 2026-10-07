<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\JobStatus;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
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
        $serializer = new JsonTransportSerializer(MessageCodecFactory::create());
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

        $started = null;
        $completed = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params) use (&$started): int {
                self::assertStringContainsString('ON CONFLICT (job_id) DO UPDATE', $sql);
                $started = $params;

                return 2;
            },
        );
        $connection->method('executeStatement')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params) use (&$completed): int {
                self::assertStringContainsString('WHERE job_id = :job_id AND attempt = :attempt', $sql);
                $completed[] = $params;

                return 1;
            },
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

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
        $serializer = new JsonTransportSerializer(MessageCodecFactory::create());
        $task = new \Swoole\Server\Task();
        $task->data = $serializer->encode($envelope);
        $server = new \Swoole\Server('127.0.0.1', 0);
        $decorator = new SwooleTaskJobMonitorDecorator(
            new SwooleServerTaskTransportHandler($bus, $serializer),
            new JobMonitorService($em, new CursorPaginator(), new JsonEncoder()),
            new JobMessageSerializer(MessageCodecFactory::create()),
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
        self::assertIsArray($started);
        self::assertSame('swoole_task', $started['queue']);
        $monitorId = $started['job_id'];
        self::assertSame($monitorId, PublicId::fromString($monitorId)->toString());

        if ($suppliedId) {
            self::assertSame($jobId->toString(), $monitorId);
        } else {
            self::assertNotSame($jobId->toString(), $monitorId);
        }

        self::assertSame((MessageCodecFactory::create())->encode($command), $started['data']);
        self::assertFalse($started['data_truncated']);
        self::assertCount(1, $completed);
        self::assertSame(($fails ? JobStatus::Failed : JobStatus::Finished)->value, $completed[0]['status']);
        self::assertSame($monitorId, $completed[0]['job_id']);
        self::assertSame(2, $completed[0]['attempt']);
    }

}
