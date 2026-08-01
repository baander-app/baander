<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use SwooleBundle\SwooleBundle\Server\HttpServer;
use Symfony\Component\Messenger\Envelope;

final readonly class HttpServerTaskDispatcher implements SwooleTaskDispatcherInterface
{
    public function __construct(private HttpServer $httpServer) {}

    public function dispatchTask(Envelope $envelope): bool
    {
        return $this->httpServer->dispatchTask($envelope);
    }
}
