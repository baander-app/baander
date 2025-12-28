<?php

namespace App\Modules\Transcoder\Protocol;

use App\Extensions\EnumExt;

/**
 * Socket error codes from server
 */
enum ErrorCode: int
{
    use EnumExt;

    case UNKNOWN_MESSAGE_TYPE = 1;
    case INVALID_REQUEST = 2;
    case INTERNAL_ERROR = 3;
    case NOT_FOUND = 4;
    case BAD_REQUEST = 5;

    /**
     * Get human-readable description
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::UNKNOWN_MESSAGE_TYPE => 'Unknown message type',
            self::INVALID_REQUEST => 'Invalid request format',
            self::INTERNAL_ERROR => 'Internal server error',
            self::NOT_FOUND => 'Resource not found',
            self::BAD_REQUEST => 'Bad request',
        };
    }

    /**
     * Get corresponding HTTP status code
     */
    public function getHttpStatusCode(): int
    {
        return match ($this) {
            self::UNKNOWN_MESSAGE_TYPE, self::INVALID_REQUEST, self::BAD_REQUEST => 400,
            self::INTERNAL_ERROR => 500,
            self::NOT_FOUND => 404,
        };
    }
}
