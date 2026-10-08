<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlResult;
use App\Shared\Infrastructure\Swoole\Control\ServerControlCoordinator;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperation;
use App\Shared\Infrastructure\Swoole\Control\ServerControlOperationRegistry;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;

final class ServerControlCoordinatorTest extends TestCase
{
    private const float TIMEOUT = 0.05;

    /** @var array<int, ServerControlCoordinator> */
    private array $coordinators = [];

    /** @var array<int, FakeServerWorkers> */
    private array $workers = [];

    public function testFanOutReturnsOneResultPerWorker(): void
    {
        $this->cluster(3, $this->operation('test.echo', true, static fn (array $payload, int $worker): array => [$worker, $payload['value']]));

        $result = $this->executeOn(1, 'test.echo', ['value' => 'x']);

        self::assertSame([0 => [0, 'x'], 1 => [1, 'x'], 2 => [2, 'x']], $result->results);
        self::assertSame([], $result->missingWorkers);
        self::assertTrue($result->isComplete());
        self::assertSame([0, 2], array_column($this->workers[1]->sent, 'to'));
    }

    public function testWorkerThatDoesNotReplyIsMissingAndTheResultIsPartial(): void
    {
        $this->cluster(3, $this->operation('test.echo', true, static fn (array $payload, int $worker): int => $worker));
        $this->workers[0]->silent = [2];

        $result = $this->executeOn(0, 'test.echo');

        self::assertSame([0 => 0, 1 => 1], $result->results);
        self::assertSame([2], $result->missingWorkers);
        self::assertFalse($result->isComplete());
    }

    public function testUndeliverableMessageCountsAsMissing(): void
    {
        $this->cluster(2, $this->operation('test.echo', true, static fn (array $payload, int $worker): int => $worker));
        $this->workers[0]->undeliverable = [1];

        $result = $this->executeOn(0, 'test.echo');

        self::assertSame([0 => 0], $result->results);
        self::assertSame([1], $result->missingWorkers);
    }

    public function testFailingWorkerIsReportedAsAnError(): void
    {
        $this->cluster(2, $this->operation('test.fail', true, static fn (array $payload, int $worker): int => $worker === 1
            ? throw new RuntimeException('table full')
            : $worker));

        $result = $this->executeOn(0, 'test.fail');

        self::assertSame([0 => 0], $result->results);
        self::assertSame([1 => 'table full'], $result->errors);
        self::assertSame([], $result->missingWorkers);
        self::assertFalse($result->isComplete());
    }

    public function testServerWideOperationSkipsFanOut(): void
    {
        $this->cluster(3, $this->operation('test.stats', false, static fn (array $payload, int $worker): string => 'stats'));

        $result = $this->executeOn(2, 'test.stats');

        self::assertSame([2 => 'stats'], $result->results);
        self::assertTrue($result->isComplete());
        self::assertSame([], $this->workers[2]->sent);
    }

    public function testUnknownOperationIsRejectedWithoutReachingOtherWorkers(): void
    {
        $this->cluster(3, $this->operation('test.echo', true, static fn (array $payload, int $worker): int => $worker));

        try {
            $this->executeOn(0, 'test.unknown');
            self::fail('Unknown operation was accepted.');
        } catch (ServerControlException $exception) {
            self::assertSame('unknown server control operation "test.unknown"', $exception->getMessage());
        }
        self::assertSame([], $this->workers[0]->sent);
    }

    public function testOutsideAnHttpWorkerTheCoordinatorRefuses(): void
    {
        $this->cluster(1, $this->operation('test.echo', true, static fn (array $payload, int $worker): int => $worker));
        $this->workers[0]->current = null;

        self::assertFalse($this->coordinators[0]->runsInHttpWorker());
        $this->expectException(ServerControlException::class);
        $this->coordinators[0]->execute('test.echo');
    }

    /** @param Closure(array<string, mixed>, int): mixed $handle */
    private function operation(string $name, bool $fansOut, Closure $handle): Closure
    {
        return static fn (FakeServerWorkers $workers): ServerControlOperation => new class ($name, $fansOut, $handle, $workers) implements ServerControlOperation {
            public function __construct(
                private string $name,
                private bool $fansOut,
                private Closure $handle,
                private FakeServerWorkers $workers,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function fansOut(): bool
            {
                return $this->fansOut;
            }

            public function handle(array $payload): mixed
            {
                return ($this->handle)($payload, $this->workers->current);
            }
        };
    }

    /** @param Closure(FakeServerWorkers): ServerControlOperation $operation */
    private function cluster(int $size, Closure $operation): void
    {
        for ($worker = 0; $worker < $size; ++$worker) {
            $this->workers[$worker] = new FakeServerWorkers($worker, $size, $this->deliver(...));
            $this->coordinators[$worker] = new ServerControlCoordinator(
                new ServerControlOperationRegistry([$operation($this->workers[$worker])]),
                $this->workers[$worker],
                self::TIMEOUT,
            );
        }
    }

    /**
     * Pipe-message delivery between the fake workers, as ControlPipeMessageHandler routes it.
     *
     * @param array<string, mixed> $message
     */
    private function deliver(array $message, int $from, int $to): void
    {
        Coroutine::create(function () use ($message, $from, $to): void {
            if ($message[ServerControlCoordinator::MESSAGE_MARKER] === 'request') {
                $this->coordinators[$to]->answer($message, $from);
            } else {
                $this->coordinators[$to]->deliverReply($message);
            }
        });
    }

    /** @param array<string, mixed> $payload */
    private function executeOn(int $worker, string $operation, array $payload = []): ServerControlResult
    {
        $result = null;
        $failure = null;
        Coroutine\run(function () use ($worker, $operation, $payload, &$result, &$failure): void {
            try {
                $result = $this->coordinators[$worker]->execute($operation, $payload);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
        self::assertInstanceOf(ServerControlResult::class, $result);

        return $result;
    }
}
