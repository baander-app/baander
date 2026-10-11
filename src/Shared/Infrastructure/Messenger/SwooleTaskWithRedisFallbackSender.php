<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Port\ServerDrainStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

/**
 * Sends swoole_task messages to the server's task workers, and to the Redis async transport when
 * the task queue refuses them. While the server drains, every message goes to Redis: the task
 * workers stop after the drain window, and whatever their in-memory queue still holds is lost.
 * The swoole_task handlers also accept messages from async, so the worker handles them.
 */
final readonly class SwooleTaskWithRedisFallbackSender implements SenderInterface
{
    /** The container passes the drain state; it is optional only for tests. */
    public function __construct(
        private SwooleTaskDispatcherInterface $dispatcher,
        private SenderInterface $redisFallback,
        private LoggerInterface $logger,
        private ?ServerDrainStateInterface $drain = null,
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
        if ($this->drain?->isDraining() === true) {
            return $this->redisFallback->send($taskEnvelope);
        }

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
