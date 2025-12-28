<?php

namespace App\Modules\Transcoder\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for all socket-related errors
 */
abstract class SocketException extends RuntimeException
{
    /**
     * Create exception with context
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param Throwable|null $previous Previous exception
     * @param array<string, mixed> $context Additional context
     */
    public function __construct(
        string          $message = '',
        int             $code = 0,
        ?Throwable      $previous = null,
        protected array $context = [],
    )
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get additional context
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get context value by key
     *
     * @param string $key Context key
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}
