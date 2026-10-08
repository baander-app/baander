<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole\Control;

use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerControlResult;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * In-server implementation of the server control port. Runs an operation in the
 * current HTTP worker and, for fan-out operations, in every other HTTP worker
 * through pipe messages, waiting a bounded time for their replies.
 */
final class ServerControlCoordinator implements ServerControlPortInterface
{
    /** How long the accepting worker waits for the other workers' replies. */
    public const float REPLY_TIMEOUT_SECONDS = 2.0;

    public const string MESSAGE_MARKER = 'baander_server_control';

    /** @var array<string, Channel> fan-out ID => channel collecting replies */
    private array $pending = [];

    public function __construct(
        private readonly ServerControlOperationRegistry $operations,
        private readonly ServerWorkers $workers,
        private readonly float $replyTimeoutSeconds = self::REPLY_TIMEOUT_SECONDS,
    ) {
    }

    public function runsInHttpWorker(): bool
    {
        return $this->workers->currentHttpWorkerId() !== null;
    }

    public function execute(string $operation, array $payload = []): ServerControlResult
    {
        $self = $this->workers->currentHttpWorkerId()
            ?? throw new ServerControlException('server control operations run in-process only inside an HTTP worker');
        $handler = $this->operations->get($operation);
        if (!$handler->fansOut()) {
            return $this->result([$self => $this->apply($handler, $payload)], []);
        }

        $id = bin2hex(random_bytes(8));
        $others = array_values(array_diff($this->workers->httpWorkerIds(), [$self]));
        $replies = new Channel(max(1, count($others)));
        $this->pending[$id] = $replies;
        try {
            $awaiting = [];
            $request = [self::MESSAGE_MARKER => 'request', 'id' => $id, 'op' => $operation, 'payload' => $payload];
            foreach ($others as $workerId) {
                if ($this->workers->send($request, $workerId)) {
                    $awaiting[$workerId] = true;
                }
            }
            $outcomes = [$self => $this->apply($handler, $payload)];

            $deadline = hrtime(true) + (int) ($this->replyTimeoutSeconds * 1e9);
            while ($awaiting !== []) {
                $remaining = ($deadline - hrtime(true)) / 1e9;
                if ($remaining <= 0) {
                    break;
                }
                $reply = $replies->pop($remaining);
                if (!is_array($reply)) {
                    break;
                }
                $workerId = $reply['worker'];
                if (!isset($awaiting[$workerId])) {
                    continue;
                }
                unset($awaiting[$workerId]);
                $outcomes[$workerId] = $reply['outcome'];
            }
        } finally {
            unset($this->pending[$id]);
        }

        return $this->result($outcomes, array_values(array_diff($others, array_keys($outcomes))));
    }

    /**
     * Runs a fan-out request received from another worker and replies to it.
     *
     * @param array<string, mixed> $message
     */
    public function answer(array $message, int $fromWorkerId): void
    {
        $self = $this->workers->currentHttpWorkerId();
        if ($self === null || !is_string($message['id'] ?? null) || !is_string($message['op'] ?? null)) {
            return;
        }
        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        try {
            $outcome = $this->apply($this->operations->get($message['op']), $payload);
        } catch (ServerControlException $exception) {
            $outcome = ['ok' => false, 'error' => $exception->getMessage()];
        }
        $this->workers->send(
            [self::MESSAGE_MARKER => 'reply', 'id' => $message['id'], 'worker' => $self, 'outcome' => $outcome],
            $fromWorkerId,
        );
    }

    /**
     * Hands a worker's reply to the waiting fan-out; a reply that arrives after the
     * timeout finds no waiter and is dropped.
     *
     * @param array<string, mixed> $message
     */
    public function deliverReply(array $message): void
    {
        $id = $message['id'] ?? null;
        if (!is_string($id) || !is_int($message['worker'] ?? null) || !is_array($message['outcome'] ?? null)) {
            return;
        }
        // Each worker replies once, so the channel has room; the short timeout only
        // guards against a duplicate reply parking this coroutine.
        ($this->pending[$id] ?? null)?->push($message, 0.001);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{ok: true, data: mixed}|array{ok: false, error: string}
     */
    private function apply(ServerControlOperation $operation, array $payload): array
    {
        try {
            return ['ok' => true, 'data' => $operation->handle($payload)];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $outcomes
     * @param list<int> $missing
     */
    private function result(array $outcomes, array $missing): ServerControlResult
    {
        ksort($outcomes);
        sort($missing);
        $results = [];
        $errors = [];
        foreach ($outcomes as $workerId => $outcome) {
            if (($outcome['ok'] ?? false) === true) {
                $results[$workerId] = $outcome['data'] ?? null;
            } else {
                $errors[$workerId] = is_string($outcome['error'] ?? null) ? $outcome['error'] : 'operation failed';
            }
        }

        return new ServerControlResult($results, $errors, $missing);
    }
}
