<?php

namespace App\Events\Auth;

use App\Models\Auth\OAuth\Token;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class TokenIssuedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user,
        public Token $token,
        public ?string $sessionId = null,
        public array $scopes = [],
    ) {
        //
    }

    /**
     * Check if this is a refresh token.
     */
    public function isRefresh(): bool
    {
        return false;
    }
}
