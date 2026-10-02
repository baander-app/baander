<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
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
}
