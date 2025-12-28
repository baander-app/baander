<?php

namespace App\Modules\Transcoder\Exception;

/**
 * Write operation exceptions
 */
class WriteException extends SocketException
{
    /**
     * Write timeout
     */
    public static function timeout(int $expectedBytes, int $actualBytes): self
    {
        return new self(
            "Write timeout: expected {$expectedBytes} bytes, wrote {$actualBytes}",
            0,
            null,
            ['expected' => $expectedBytes, 'actual' => $actualBytes],
        );
    }

    /**
     * Incomplete write
     */
    public static function incomplete(int $expectedBytes, int $actualBytes): self
    {
        return new self(
            "Incomplete write: expected {$expectedBytes} bytes, wrote {$actualBytes}",
            0,
            null,
            ['expected' => $expectedBytes, 'actual' => $actualBytes],
        );
    }

    /**
     * Write failure
     */
    public static function failed(string $reason, array $extra = []): self
    {
        return new self(
            "Failed to write to socket: {$reason}",
            0,
            null,
            ['reason' => $reason, 'extra' => $extra],
        );
    }
}
