<?php

namespace App\Events\Auth;

use App\Models\Auth\OAuth\Token;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class TokenRevokedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user,
        public Token $token,
        public ?string $reason = null,
    ) {
        //
    }
}
