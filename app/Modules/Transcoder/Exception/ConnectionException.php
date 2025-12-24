<?php

namespace App\Modules\Transcoder\Exception;

use Throwable;

/**
 * Connection-related exceptions
 */
class ConnectionException extends SocketException
{
    public function __construct(
        string                  $message,
        private readonly string $socketPath,
        int                     $code = 0,
        ?Throwable              $previous = null,
    )
    {
        parent::__construct($message, $code, $previous, [
            'socket_path' => $socketPath,
        ]);
    }

    /**
     * Create from socket error
     */
    public static function fromError(
        string     $socketPath,
        int        $errno,
        string     $errstr,
        ?Throwable $previous = null,
    ): self
    {
        return new self(
            "Failed to connect to socket '{$socketPath}': [{$errno}] {$errstr}",
            $socketPath,
            $errno,
            $previous,
        );
    }

    /**
     * Create for timeout
     */
    public static function timeout(string $socketPath, float $timeout): self
    {
        return new self(
            "Connection timeout after {$timeout} seconds",
            $socketPath,
            0,
        );
    }

    /**
     * Create for already connected state
     */
    public static function alreadyConnected(string $socketPath): self
    {
        return new self(
            "Already connected to '{$socketPath}'",
            $socketPath,
            0,
        );
    }

    /**
     * Create for not connected state
     */
    public static function notConnected(): self
    {
        return new self(
            "Not connected to socket",
            '',
            0,
        );
    }

    public function getSocketPath(): string
    {
        return $this->socketPath;
    }
}
