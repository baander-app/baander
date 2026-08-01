<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger;

use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Server\HttpServer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializes the Messenger Envelope before dispatching via Server::task() in thread mode.
 *
 * In thread mode, Server::task() shares objects by reference (shared address space).
 * Without serialization, the Envelope (containing message objects, stamps, and potentially
 * Doctrine entities) would be shared between sender and task worker, creating a data race.
 *
 * In process mode, Server::task() uses IPC (separate address spaces), so serialization
 * is unnecessary and the Envelope is passed through directly.
 */
final readonly class ThreadSafeTaskDispatcher
{
    public function __construct(
        private SerializerInterface $serializer,
        private Swoole $swoole,
    ) {}

    public function dispatchTask(HttpServer $httpServer, Envelope $envelope): bool
    {
        if ($this->swoole->isThreadMode()) {
            // Thread mode: serialize to prevent shared-reference data race
            $encodedEnvelope = $this->serializer->encode($envelope);

            return $httpServer->dispatchTask($encodedEnvelope);
        }

        // Process mode: pass-through (separate address spaces, safe by reference)
        return $httpServer->dispatchTask($envelope);
    }
}
