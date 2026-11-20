<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\LogViewer\Agent;

/** Decodes Docker's multiplexed stdout/stderr without assuming transport boundaries. */
final class DockerFrameDecoder
{
    private string $buffer = '';
    private int $streamingRemaining = 0;
    private int $streamingStream = 1;

    /** @return list<array{stream:string,data:string}> */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $frames = [];
        $offset = 0;
        $bufferLength = strlen($this->buffer);
        try {
            while ($bufferLength - $offset >= 8 || $this->streamingRemaining > 0 && $bufferLength > $offset) {
                if ($this->streamingRemaining > 0) {
                    $length = min(65536, $this->streamingRemaining, $bufferLength - $offset);
                    $frames[] = ['stream' => 1 === $this->streamingStream ? 'stdout' : 'stderr', 'data' => substr($this->buffer, $offset, $length)];
                    $offset += $length;
                    $this->streamingRemaining -= $length;
                    continue;
                }
                $stream = ord($this->buffer[$offset]);
                if (!in_array($stream, [1, 2], true) || "\0\0\0" !== substr($this->buffer, $offset + 1, 3)) {
                    throw new \RuntimeException('Invalid Docker log frame.');
                }
                $length = unpack('Nlength', substr($this->buffer, $offset + 4, 4))['length'];
                if ($length > 1048576) {
                    $this->streamingRemaining = $length;
                    $this->streamingStream = $stream;
                    $offset += 8;
                    continue;
                }
                if ($bufferLength - $offset < 8 + $length) {
                    break;
                }
                $frames[] = ['stream' => 1 === $stream ? 'stdout' : 'stderr', 'data' => substr($this->buffer, $offset + 8, $length)];
                $offset += 8 + $length;
            }
        } finally {
            $this->buffer = substr($this->buffer, $offset);
        }

        return $frames;
    }

    public function hasIncompleteFrame(): bool
    {
        return '' !== $this->buffer || $this->streamingRemaining > 0;
    }

    public function finish(): void
    {
        if ($this->hasIncompleteFrame()) {
            throw new \RuntimeException('Incomplete Docker log frame.');
        }
    }
}
