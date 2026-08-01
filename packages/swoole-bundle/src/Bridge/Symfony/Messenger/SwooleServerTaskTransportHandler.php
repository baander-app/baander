<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger;

use Assert\Assertion;
use Swoole\Server;
use SwooleBundle\SwooleBundle\Server\TaskHandler\TaskHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class SwooleServerTaskTransportHandler implements TaskHandler
{
    public function __construct(
        private MessageBusInterface $bus,
        private ?SerializerInterface $serializer = null,
        private ?TaskHandler $decorated = null,
    ) {}

    public function handle(Server $server, Server\Task $task): void
    {
        $data = $task->data;

        // Thread mode: data arrives as a serialized array (encoded by ThreadSafeTaskDispatcher)
        // Process mode: data arrives as a raw Envelope object
        if (is_array($data)) {
            Assertion::notNull($this->serializer, 'Cannot deserialize task data: SerializerInterface not available.');
            $data = $this->serializer->decode($data);
        }

        Assertion::isInstanceOf($data, Envelope::class);
        /** @var Envelope $data */
        $this->bus->dispatch($data);

        if (!($this->decorated instanceof TaskHandler)) {
            return;
        }

        $this->decorated->handle($server, $task);
    }
}
