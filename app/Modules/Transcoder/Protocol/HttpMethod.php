<?php

namespace App\Modules\Transcoder\Protocol;

use App\Extensions\EnumExt;

/**
 * HTTP methods
 */
enum HttpMethod: string
{
    use EnumExt;

    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';
    case PATCH = 'PATCH';

    /**
     * Check if method can have a body
     */
    public function canHaveBody(): bool
    {
        return match ($this) {
            self::POST, self::PUT, self::PATCH => true,
            default => false,
        };
    }

    /**
     * Check if method is safe (no side effects)
     */
    public function isSafe(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::OPTIONS => true,
            default => false,
        };
    }

    /**
     * Check if method is idempotent
     */
    public function isIdempotent(): bool
    {
        return match ($this) {
            self::GET, self::HEAD, self::PUT, self::DELETE, self::OPTIONS => true,
            default => false,
        };
    }
}
