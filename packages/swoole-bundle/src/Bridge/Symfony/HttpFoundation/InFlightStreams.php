<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation;

use Closure;
use Swoole\Coroutine;
use Swoole\Http\Response as SwooleResponse;
use SwooleBundle\SwooleBundle\Server\HttpServer;

/**
 * The streamed responses this worker is still sending, each with the coroutine that writes it
 * (StreamedResponseProcessor). abortAll() cuts them all, for a server that stops.
 *
 * A cut resets the connection instead of ending the response, so the client never receives the
 * terminating chunk (or the rest of a Content-Length body) and sees a transfer error instead of a
 * clean end. The reset also drops output still queued for a client that stopped reading: a
 * connection closed without a reset stays open in its reactor thread until that output is sent,
 * and Swoole cannot stop while it is. The writing coroutine's wait is cancelled too: a coroutine
 * asleep while it waits for more to send wakes at once, and code that checks for cancellation
 * stops there.
 */
final class InFlightStreams
{
    /** @var array<int, array{response: SwooleResponse, aborted: bool}> by the id of the writing coroutine */
    private array $streams = [];

    /** @param Closure(int): bool $resetConnection closes the connection with this fd with a reset */
    public function __construct(private readonly Closure $resetConnection)
    {
    }

    public static function forServer(HttpServer $server): self
    {
        return new self(static function (int $fd) use ($server): bool {
            $swoole = $server->getServer();

            return $swoole->exist($fd) && $swoole->close($fd, true);
        });
    }

    /** Called by the coroutine that writes the response, before it writes. */
    public function add(SwooleResponse $response): void
    {
        $this->streams[Coroutine::getCid()] = ['response' => $response, 'aborted' => false];
    }

    /**
     * Called by the coroutine that wrote the response, once it stops writing.
     *
     * @return bool whether the response was cut
     */
    public function remove(): bool
    {
        $cid = Coroutine::getCid();
        $aborted = $this->streams[$cid]['aborted'] ?? false;
        unset($this->streams[$cid]);

        return $aborted;
    }

    /** Whether the response the current coroutine writes was cut. */
    public function isAborted(): bool
    {
        return $this->streams[Coroutine::getCid()]['aborted'] ?? false;
    }

    public function count(): int
    {
        return count($this->streams);
    }

    /**
     * Closes the response's connection with a reset, without ending the response. end() fails
     * afterwards and writes nothing.
     */
    public function reset(SwooleResponse $response): void
    {
        ($this->resetConnection)($response->fd);
    }

    /**
     * Cuts every response still being sent and cancels the wait of its coroutine.
     *
     * Each connection is reset once. A coroutine that was busy in an operation that cannot be
     * cancelled, such as file I/O, is not woken; calling this again cancels its next wait.
     *
     * @return int the responses this call cut
     */
    public function abortAll(): int
    {
        $cut = 0;
        foreach ($this->streams as $cid => $stream) {
            if (!$stream['aborted']) {
                $this->streams[$cid]['aborted'] = true;
                $this->reset($stream['response']);
                ++$cut;
            }
            Coroutine::cancel($cid);
        }

        return $cut;
    }
}
