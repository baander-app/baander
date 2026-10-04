<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Tests\Fixtures\Messaging\MessageCodecFactory;
use App\Metadata\Application\Command\ExtractAlbumCoverCommand;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\JsonTransportSerializer;
use PHPUnit\Framework\TestCase;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger\SwooleServerTaskTransportFactory;
use SwooleBundle\SwooleBundle\Server\HttpServer;
use SwooleBundle\SwooleBundle\Server\HttpServerConfiguration;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class SwooleTransportFactoryTest extends TestCase
{
    public function testTransportUsesInjectedSenderInsteadOfBypassingItsFallback(): void
    {
        $factory = new SwooleServerTaskTransportFactory(new HttpServer($this->createStub(HttpServerConfiguration::class)));
        $envelope = new Envelope(new ExtractAlbumCoverCommand(Uuid::v4()));
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects($this->once())->method('send')->with($envelope)->willReturn($envelope);
        $factory->setSender($sender);
        $transport = $factory->createTransport('swoole://task', [], new JsonTransportSerializer(MessageCodecFactory::create()));
        self::assertSame($envelope, $transport->send($envelope));
    }
}
