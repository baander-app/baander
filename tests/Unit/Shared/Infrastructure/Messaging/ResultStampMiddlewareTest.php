<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messaging;

use App\Shared\Infrastructure\Messenger\ResultStampMiddleware;
use App\Shared\Infrastructure\Messenger\Stamp\IntResultStamp;
use App\Shared\Infrastructure\Messenger\Stamp\StringResultStamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class ResultStampMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function results(): iterable
    {
        yield 'string' => ['completed'];
        yield 'integer' => [42];
        yield 'null' => [null];
        yield 'unmatched' => [false];
    }

    #[DataProvider('results')]
    public function testRealBusContinuesTheStackAndMapsSupportedHandlerResults(mixed $result): void
    {
        $message = new \stdClass();
        $handled = 0;
        $bus = new MessageBus([
            new ResultStampMiddleware([StringResultStamp::class, IntResultStamp::class]),
            new HandleMessageMiddleware(new HandlersLocator([
                \stdClass::class => [static function (\stdClass $message) use (&$handled, $result): mixed {
                    ++$handled;

                    return $result;
                }],
            ])),
        ]);

        $envelope = $bus->dispatch($message);

        self::assertSame(1, $handled);
        self::assertSame($message, $envelope->getMessage());
        self::assertSame(is_string($result) ? $result : null, $envelope->last(StringResultStamp::class)?->getResult());
        self::assertSame(is_int($result) ? $result : null, $envelope->last(IntResultStamp::class)?->getResult());
    }

    public function testNoHandlerResultLeavesTheEnvelopeUnchanged(): void
    {
        $envelope = new Envelope(new \stdClass());
        $terminal = new class implements MiddlewareInterface {
            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $envelope;
            }
        };
        $bus = new MessageBus([new ResultStampMiddleware([StringResultStamp::class]), $terminal]);

        $dispatched = $bus->dispatch($envelope);

        self::assertSame($envelope->getMessage(), $dispatched->getMessage());
        self::assertNull($dispatched->last(StringResultStamp::class));
    }
}
