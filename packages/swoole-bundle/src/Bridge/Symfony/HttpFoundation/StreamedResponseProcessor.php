<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation;

use Assert\Assertion;
use Swoole\Http\Response as SwooleResponse;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sends a streamed response from a coroutine of its own.
 *
 * A response that ends normally is ended with end(), which writes the terminating chunk. A
 * response that is cut has its connection reset instead (InFlightStreams::reset()): the client
 * never receives the terminating chunk (or the rest of a Content-Length body) and sees a transfer
 * error, never a clean end of the body.
 * A response is cut when its content fails (the callback or generator throws) and when the server
 * stops (InFlightStreams::abortAll()).
 */
final readonly class StreamedResponseProcessor implements ResponseProcessor
{
    /**
     * Buffer size for range file serving via Swoole write().
     * 256KB balances throughput vs memory per connection.
     */
    private const RANGE_BUFFER_SIZE = 262_144;

    /**
     * Default buffer size for generic StreamedResponse output capture.
     */
    private const DEFAULT_BUFFER_SIZE = 81_92;

    public function __construct(
        private CoWrapper $coWrapper,
        private InFlightStreams $streams,
        private int $bufferOutputSize = self::DEFAULT_BUFFER_SIZE,
    ) {}

    public function process(HttpFoundationResponse $httpFoundationResponse, SwooleResponse $swooleResponse): void
    {
        if ($httpFoundationResponse instanceof SwooleRangeFileResponse) {
            $this->processRangeFile($httpFoundationResponse, $swooleResponse);

            return;
        }

        Assertion::isInstanceOf($httpFoundationResponse, StreamedResponse::class);

        // Spawn a detached coroutine for the streaming callback.
        //
        // Without this, the streaming callback blocks inside kernel.response,
        // which is inside HttpKernel::handle(), which holds pooled service
        // instances (RequestStack, EntityManager, etc.) for the entire duration
        // of the stream (up to 1 hour for SSE). This exhausts the service pool
        // and deadlocks all workers.
        //
        // By spawning a coroutine via CoWrapper's go(), the main request
        // coroutine returns immediately — releasing all pooled services back
        // to the pool. The streaming coroutine gets its own fresh instances
        // from the pool when it accesses proxied services, and releases them
        // via CoWrapper's defer() when it finishes.
        $this->coWrapper->go(function () use ($httpFoundationResponse, $swooleResponse): void {
            $this->streams->add($swooleResponse);
            $clientGone = false;
            $streams = $this->streams;

            ob_start(static function (string $payload) use ($swooleResponse, $streams, &$clientGone): string {
                if ($payload !== '' && !$clientGone && !$streams->isAborted()) {
                    $clientGone = !$swooleResponse->write($payload);
                }

                return '';
            }, $this->bufferOutputSize);

            $failure = null;
            try {
                $httpFoundationResponse->sendContent();
            } catch (Throwable $error) {
                $failure = $error;
            } finally {
                ob_end_clean();
                $this->finish($swooleResponse, $failure);
            }
        });
    }

    /**
     * Serve a byte range from a file with optimized Swoole I/O.
     *
     * Uses Swoole's write() directly with a large buffer (256KB) for high
     * throughput on media streaming. Runs in a detached coroutine so the
     * kernel request lifecycle completes immediately.
     */
    private function processRangeFile(SwooleRangeFileResponse $response, SwooleResponse $swooleResponse): void
    {
        $this->coWrapper->go(function () use ($response, $swooleResponse): void {
            $this->streams->add($swooleResponse);
            $failure = null;
            $stream = null;

            try {
                $stream = fopen($response->path, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Cannot open ' . $response->path);
                }
                fseek($stream, $response->getOffset());

                $remaining = $response->getLength();
                $bufferSize = self::RANGE_BUFFER_SIZE;

                while ($remaining > 0 && !$this->streams->isAborted()) {
                    $read = min($bufferSize, $remaining);
                    $data = fread($stream, $read);

                    if ($data === false || $data === '') {
                        throw new \RuntimeException(sprintf('%s ended %d bytes before the range did', $response->path, $remaining));
                    }

                    // write() returns false when the client has disconnected.
                    // Stop streaming immediately to avoid wasting I/O and holding
                    // pooled service instances for a dead connection.
                    if (!$swooleResponse->write($data)) {
                        break;
                    }

                    $remaining -= strlen($data);
                }
            } catch (Throwable $error) {
                $failure = $error;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $this->finish($swooleResponse, $failure);
            }
        });
    }

    /**
     * Ends the response, or, when it was cut or its content failed, resets the connection
     * without ending it.
     */
    private function finish(SwooleResponse $swooleResponse, ?Throwable $failure): void
    {
        $aborted = $this->streams->remove();
        if ($failure !== null && !$aborted) {
            // A cut connection is expected to fail the content; any other failure is reported.
            error_log(sprintf(
                '[StreamedResponse] %s: %s in %s:%d; the connection was reset without ending the response.',
                $failure::class,
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine(),
            ));
            $this->streams->reset($swooleResponse);
        }
        // After a reset, end() writes nothing; it only releases the request in Swoole, which
        // counts it in worker_concurrency until then.
        $swooleResponse->end();
    }
}
