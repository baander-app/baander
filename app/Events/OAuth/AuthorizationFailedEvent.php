<?php

namespace App\Events\OAuth;

use App\Models\Auth\OAuth\Client;
use Illuminate\Foundation\Events\Dispatchable;
use League\OAuth2\Server\Exception\OAuthServerException;

class AuthorizationFailedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public OAuthServerException $exception,
        public ?Client $client = null,
        public ?string $grantType = null,
    ) {
        //
    }

    /**
     * Get the error type.
     */
    public function errorType(): string
    {
        return $this->exception->getErrorType();
    }

    /**
     * Get the error message.
     */
    public function errorMessage(): string
    {
        return $this->exception->getMessage();
    }

    /**
     * Get the HTTP status code.
     */
    public function httpStatusCode(): int
    {
        return $this->exception->getHttpStatusCode();
    }
}
