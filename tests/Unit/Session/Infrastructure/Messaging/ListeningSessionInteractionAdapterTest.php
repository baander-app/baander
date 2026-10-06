<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session\Infrastructure\Messaging;

use App\Session\Application\Command\SessionJoinCommand;
use App\Session\Application\Command\SessionPlaybackCommand;
use App\Session\Application\Command\SyncSessionCommand;
use App\Session\Infrastructure\Messaging\ListeningSessionInteractionAdapter;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ListeningSessionInteractionAdapterTest extends TestCase
{
    #[DataProvider('operations')]
    public function testDispatchesExactCommandAndReturnsLastHandledResult(string $operation, string $commandClass): void
    {
        $userId = Uuid::generate();
        $deviceId = Uuid::generate();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            function (object $command) use ($operation, $commandClass, $userId, $deviceId): Envelope {
                self::assertInstanceOf($commandClass, $command);
                self::assertSame($userId, $command->getUserId());
                self::assertSame($deviceId, $command->getDeviceId());
                if ($command instanceof SessionPlaybackCommand) {
                    self::assertSame('seek', $command->getAction());
                }
                if ($command instanceof SessionPlaybackCommand || $command instanceof SyncSessionCommand) {
                    self::assertSame(12.5, $command->getPosition());
                    self::assertSame(['track-1'], $command->getQueue());
                    self::assertSame(2, $command->getCurrentTrackIndex());
                    self::assertSame('playing', $command->getPlaybackState());
                }

                return new Envelope($command, [
                    new HandledStamp(['ignored'], 'first'),
                    new HandledStamp(['operation' => $operation], 'last'),
                ]);
            },
        );
        $adapter = new ListeningSessionInteractionAdapter($bus);

        self::assertSame(
            ['operation' => $operation],
            $this->invoke($adapter, $operation, $userId, $deviceId),
        );
    }

    #[DataProvider('results')]
    public function testPreservesHandledResultAndFallback(string $operation, bool $hasStamp, mixed $result, mixed $expected): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $command): Envelope => new Envelope(
            $command, $hasStamp ? [new HandledStamp($result, 'handler')] : [],
        ));
        $adapter = new ListeningSessionInteractionAdapter($bus);

        self::assertSame(
            $expected,
            $this->invoke($adapter, $operation, Uuid::generate(), Uuid::generate()),
        );
    }

    #[DataProvider('operations')]
    public function testPropagatesSameHandlerFailure(string $operation, string $commandClass): void
    {
        $failure = new HandlerFailedException(new Envelope(new \stdClass()), [new \RuntimeException('denied')]);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willThrowException($failure);
        $adapter = new ListeningSessionInteractionAdapter($bus);

        try {
            $this->invoke($adapter, $operation, Uuid::generate(), Uuid::generate());
            self::fail('Expected handler failure.');
        } catch (HandlerFailedException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function operations(): iterable
    {
        yield 'join' => ['join', SessionJoinCommand::class];
        yield 'playback' => ['playback', SessionPlaybackCommand::class];
        yield 'sync' => ['sync', SyncSessionCommand::class];
    }

    /** @return iterable<string, array{string, bool, mixed, mixed}> */
    public static function results(): iterable
    {
        foreach (['join', 'playback', 'sync'] as $operation) {
            yield $operation . ' missing stamp' => [$operation, false, null, []];
            yield $operation . ' null result' => [$operation, true, null, []];
            yield $operation . ' false result' => [$operation, true, false, false];
            yield $operation . ' zero result' => [$operation, true, 0, 0];
            yield $operation . ' string result' => [$operation, true, 'result', 'result'];
        }
    }

    private function invoke(
        ListeningSessionInteractionAdapter $adapter,
        string $operation,
        Uuid $userId,
        Uuid $deviceId,
    ): mixed
    {
        return match ($operation) {
            'join' => $adapter->join($userId, $deviceId),
            'playback' => $adapter->playback($userId, $deviceId, 'seek', 12.5, ['track-1'], 2, 'playing'),
            'sync' => $adapter->sync($userId, $deviceId, ['track-1'], 2, 12.5, 'playing'),
            default => throw new \InvalidArgumentException('Unknown test operation: ' . $operation),
        };
    }
}
