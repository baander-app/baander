<?php

namespace App\Events\Auth;

use App\Models\User;
use App\Models\OAuth\Token;
use App\Models\OAuth\RefreshToken;
use Illuminate\Foundation\Events\Dispatchable;

class TokenRefreshedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user,
        public Token $newAccessToken,
        public RefreshToken $newRefreshToken,
        public ?RefreshToken $previousRefreshToken = null,
    ) {
        //
    }

    /**
     * Check if token reuse was detected.
     */
    public function wasReuseDetected(): bool
    {
        return $this->previousRefreshToken !== null;
    }
}
