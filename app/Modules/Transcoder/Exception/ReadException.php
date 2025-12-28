<?php

namespace App\Modules\Transcoder\Exception;

/**
 * Read operation exceptions
 */
class ReadException extends SocketException
{
    /**
     * Read timeout
     */
    public static function timeout(int $expectedBytes, int $actualBytes): self
    {
        return new self(
            "Read timeout: expected {$expectedBytes} bytes, got {$actualBytes}",
            0,
            null,
            ['expected' => $expectedBytes, 'actual' => $actualBytes],
        );
    }

    /**
     * Incomplete read
     */
    public static function incomplete(int $expectedBytes, int $actualBytes): self
    {
        return new self(
            "Incomplete read: expected {$expectedBytes} bytes, got {$actualBytes}",
            0,
            null,
            ['expected' => $expectedBytes, 'actual' => $actualBytes],
        );
    }

    /**
     * Connection closed
     */
    public static function connectionClosed(): self
    {
        return new self(
            "Connection closed by server",
            0,
        );
    }

    /**
     * Read failure
     */
    public static function failed(string $reason): self
    {
        return new self(
            "Failed to read from socket: {$reason}",
            0,
            null,
            ['reason' => $reason],
        );
    }
}
