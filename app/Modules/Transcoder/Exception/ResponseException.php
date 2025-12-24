<?php

namespace App\Modules\Transcoder\Exception;

use Throwable;

/**
 * HTTP response exceptions
 */
class ResponseException extends SocketException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string                   $message,
        private readonly int     $httpStatusCode,
        private readonly ?string $httpStatusMessage = null,
        int                      $code = 0,
        ?Throwable               $previous = null,
        array                    $context = [],
    )
    {
        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Create from HTTP status code
     */
    public static function fromStatus(
        int     $statusCode,
        string  $statusMessage,
        ?string $body = null,
    ): self
    {
        $message = "HTTP error: {$statusCode} {$statusMessage}";

        return new self(
            $message,
            $statusCode,
            $statusMessage,
            0,
            null,
            ['body' => $body],
        );
    }

    /**
     * Create for server error response
     */
    public static function serverError(
        int     $errorCode,
        string  $errorMessage,
        ?string $details = null,
    ): self
    {
        return new self(
            "Server error [{$errorCode}]: {$errorMessage}",
            500,
            null,
            0,
            null,
            [
                'error_code'    => $errorCode,
                'error_message' => $errorMessage,
                'error_details' => $details,
            ],
        );
    }

    /**
     * Create for not found
     */
    public static function notFound(string $path): self
    {
        return new self(
            "Resource not found: {$path}",
            404,
            'Not Found',
            0,
            null,
            ['path' => $path],
        );
    }

    /**
     * Create for bad request
     */
    public static function badRequest(string $reason): self
    {
        return new self(
            "Bad request: {$reason}",
            400,
            'Bad Request',
            0,
            null,
            ['reason' => $reason],
        );
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getHttpStatusMessage(): ?string
    {
        return $this->httpStatusMessage;
    }
}
