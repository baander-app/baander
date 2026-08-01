<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation;

use Assert\Assertion;
use Swoole\Http\Response as SwooleResponse;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            $clientGone = false;

            ob_start(static function (string $payload) use ($swooleResponse, &$clientGone): string {
                if ($payload !== '' && !$clientGone) {
                    $clientGone = !$swooleResponse->write($payload);
                }

                return '';
            }, $this->bufferOutputSize);

            try {
                $httpFoundationResponse->sendContent();
            } finally {
                ob_end_clean();
                $swooleResponse->end();
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
            $stream = fopen($response->path, 'rb');

            if ($stream === false) {
                $swooleResponse->end();

                return;
            }

            try {
                fseek($stream, $response->getOffset());

                $remaining = $response->getLength();
                $bufferSize = self::RANGE_BUFFER_SIZE;

                while ($remaining > 0 && !feof($stream)) {
                    $read = min($bufferSize, $remaining);
                    $data = fread($stream, $read);

                    if ($data === false || $data === '') {
                        break;
                    }

                    // write() returns false when the client has disconnected.
                    // Stop streaming immediately to avoid wasting I/O and holding
                    // pooled service instances for a dead connection.
                    if (!$swooleResponse->write($data)) {
                        break;
                    }

                    $remaining -= strlen($data);
                }
            } finally {
                fclose($stream);
                $swooleResponse->end();
            }
        });
    }
}
