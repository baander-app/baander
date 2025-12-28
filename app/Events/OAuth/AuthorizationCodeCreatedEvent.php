<?php

namespace App\Events\OAuth;

use App\Models\Auth\OAuth\AuthCode;
use App\Models\Auth\OAuth\Client;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class AuthorizationCodeCreatedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public AuthCode $authCode,
        public User $user,
        public Client $client,
        public array $scopes = [],
    ) {
        //
    }
}
