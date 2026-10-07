<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messaging;

use App\Party\Domain\Model\PartyMember;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Messenger\ResultStampMiddleware;
use App\Shared\Infrastructure\Messenger\Stamp\PartyMemberResultStamp;
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
        yield 'party member' => [PartyMember::create(new Uuid(), new Uuid())];
        yield 'null' => [null];
        yield 'unmatched' => ['completed'];
    }

    #[DataProvider('results')]
    public function testRealBusContinuesTheStackAndMapsSupportedHandlerResults(mixed $result): void
    {
        $message = new \stdClass();
        $handled = 0;
        $bus = new MessageBus([
            new ResultStampMiddleware([PartyMemberResultStamp::class]),
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
        self::assertSame($result instanceof PartyMember ? $result : null, $envelope->last(PartyMemberResultStamp::class)?->getMember());
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
        $bus = new MessageBus([new ResultStampMiddleware([PartyMemberResultStamp::class]), $terminal]);

        $dispatched = $bus->dispatch($envelope);

        self::assertSame($envelope->getMessage(), $dispatched->getMessage());
        self::assertNull($dispatched->last(PartyMemberResultStamp::class));
    }
}
