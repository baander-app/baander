<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Scheduler\Application\Exception\ScheduledConsoleCompletionUnknown;
use App\Scheduler\Infrastructure\Messenger\StopWorkerOnUnknownScheduledConsole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/** Real Messenger lifecycle: uncertainty must stop before the next queued job. */
final class ScheduledConsoleWorkerStopTest extends TestCase
{
    #[DataProvider('failures')]
    public function testUncertainFailureStopsBeforeNextMessageButKnownFailureDoesNot(bool $unknown, bool $nested): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new \stdClass()));
        $transport->send(new Envelope(new \stdClass()));
        $events = new EventDispatcher();
        $events->addSubscriber(new StopWorkerOnUnknownScheduledConsole());
        // Bound the known-failure case, which must continue to the second message.
        $events->addSubscriber(new StopWorkerOnMessageLimitListener(2));
        $handled = 0;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            \stdClass::class => [static function (\stdClass $message) use (&$handled, $unknown, $nested): void {
                if (++$handled !== 1) {
                    return;
                }
                $failure = $unknown ? new ScheduledConsoleCompletionUnknown('Fixture child completion unknown.') : new \RuntimeException('Fixture known failure.');
                if ($nested) {
                    // Unknown is not the first nested error or the previous exception.
                    throw new HandlerFailedException(new Envelope($message), [new \RuntimeException('Unrelated first handler failure.'), $failure]);
                }
                throw $failure;
            }],
        ]))]);
        (new Worker(['test' => $transport], $bus, $events))->run(['sleep' => 0]);

        self::assertSame($unknown ? 1 : 2, $handled);
        self::assertCount(1, $transport->getRejected());
        self::assertCount($unknown ? 0 : 1, $transport->getAcknowledged());
        self::assertCount($unknown ? 1 : 0, iterator_to_array($transport->get()));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function failures(): iterable
    {
        yield 'unknown' => [true, false];
        yield 'nested unknown' => [true, true];
        yield 'known failure' => [false, false];
    }
}
