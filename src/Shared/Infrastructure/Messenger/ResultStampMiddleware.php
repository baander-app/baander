<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class ResultStampMiddleware implements MiddlewareInterface
{
    /**
     * @param class-string<StampInterface>[] $stampClasses
     */
    public function __construct(
        private array $stampClasses,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $envelope = $stack->next()->handle($envelope);

        $handledStamp = $envelope->last(HandledStamp::class);
        if ($handledStamp === null) {
            return $envelope;
        }

        $result = $handledStamp->getResult();
        if ($result === null) {
            return $envelope;
        }

        foreach ($this->stampClasses as $stampClass) {
            $stamp = $stampClass::fromResult($result);
            if ($stamp !== null) {
                return $envelope->with($stamp);
            }
        }

        return $envelope;
    }
}
