<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

final readonly class SwooleTaskWithRedisFallbackSender implements SenderInterface
{
    public function __construct(
        private SwooleTaskDispatcherInterface $dispatcher,
        private SenderInterface $redisFallback,
        private LoggerInterface $logger,
    ) {
    }

    public function send(Envelope $envelope): Envelope
    {
        // Add ReceivedStamp on a SEPARATE envelope for the task dispatch only.
        // The original envelope (without ReceivedStamp) must be returned so
        // HandleMessageMiddleware skips synchronous handling.
        $sentStamp = $envelope->last(SentStamp::class);
        $alias = $sentStamp === null ? 'swoole-task' : $sentStamp->getSenderAlias() ?? $sentStamp->getSenderClass();
        $taskEnvelope = $envelope->with(new ReceivedStamp($alias));

        try {
            $dispatched = $this->dispatcher->dispatchTask($taskEnvelope);

            if ($dispatched) {
                return $envelope;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Swoole task dispatch failed, falling back to Redis', [
                'exception' => $e->getMessage(),
            ]);
        }

        $this->logger->warning('Swoole task queue unavailable, falling back to Redis transport');

        return $this->redisFallback->send($taskEnvelope);
    }
}
