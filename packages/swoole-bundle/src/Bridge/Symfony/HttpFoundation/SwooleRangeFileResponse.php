<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A response for serving byte-range requests from a file on disk.
 *
 * Extends StreamedResponse so it flows through the existing response processor
 * chain (StreamedResponseListener → StreamedResponseProcessor). The processor
 * detects this type and uses Swoole's optimized write() with large buffer
 * sizing instead of going through PHP's output buffering layer.
 *
 * For whole-file responses, use Symfony's BinaryFileResponse instead —
 * EndResponseProcessor already routes those to Swoole's zero-copy sendfile().
 */
final class SwooleRangeFileResponse extends StreamedResponse
{
    /**
     * @param string $path Absolute path to the file on disk
     * @param int $offset Byte offset to start reading from
     * @param int $length Number of bytes to send
     * @param int $fileSize Total file size (for Content-Range header)
     * @param string $mimeType MIME type for Content-Type header
     */
    public function __construct(
        public readonly string $path,
        public readonly int $offset,
        public readonly int $length,
        public readonly int $fileSize,
        public readonly string $mimeType,
    ) {
        // No-op callback — StreamedResponseProcessor handles I/O directly
        // when it detects SwooleRangeFileResponse.
        parent::__construct(static fn () => null, 206);

        $this->headers->set('Content-Type', $mimeType);
        $this->headers->set('Content-Length', (string) $length);
        $this->headers->set('Content-Range', sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $fileSize));
        $this->headers->set('Accept-Ranges', 'bytes');
        $this->headers->set('Cache-Control', 'public, max-age=86400');
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }
}
