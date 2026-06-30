<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Logs dispatched messages. Job monitoring is handled by
 * SwooleTaskJobMonitorDecorator on the Swoole task handler chain.
 */
final readonly class JobMonitoringMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $stack->next()->handle($envelope, $stack);
    }
}
