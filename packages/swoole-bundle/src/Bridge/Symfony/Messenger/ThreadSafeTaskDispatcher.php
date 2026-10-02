<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger;

use SwooleBundle\SwooleBundle\Server\HttpServer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/** Uses the configured wire format in both process and thread mode. */
final readonly class ThreadSafeTaskDispatcher
{
    public function __construct(private SerializerInterface $serializer) {}

    public function dispatchTask(HttpServer $httpServer, Envelope $envelope): bool
    {
        return $httpServer->dispatchTask($this->serializer->encode($envelope));
    }
}
