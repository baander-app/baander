<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Messenger;

use SwooleBundle\SwooleBundle\Server\HttpServer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final readonly class SwooleServerTaskSender implements SenderInterface
{
    public function __construct(
        private HttpServer $httpServer,
        private ?ThreadSafeTaskDispatcher $threadSafeDispatcher = null,
    ) {}

    public function send(Envelope $envelope): Envelope
    {
        /** @var SentStamp|null $sentStamp */
        $sentStamp = $envelope->last(SentStamp::class);
        $alias = $sentStamp === null ? 'swoole-task' : $sentStamp->getSenderAlias() ?? $sentStamp->getSenderClass();

        $envelopeWithReceived = $envelope->with(new ReceivedStamp($alias));

        if ($this->threadSafeDispatcher !== null) {
            $this->threadSafeDispatcher->dispatchTask($this->httpServer, $envelopeWithReceived);
        } else {
            $this->httpServer->dispatchTask($envelopeWithReceived);
        }

        return $envelope;
    }
}
