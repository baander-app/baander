<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use SwooleBundle\SwooleBundle\Server\HttpServer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class HttpServerTaskDispatcher implements SwooleTaskDispatcherInterface
{
    public function __construct(private HttpServer $httpServer, private SerializerInterface $serializer) {}

    public function dispatchTask(Envelope $envelope): bool
    {
        return $this->httpServer->dispatchTask($this->serializer->encode($envelope));
    }
}
