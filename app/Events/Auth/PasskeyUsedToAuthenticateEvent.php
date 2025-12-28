<?php

namespace App\Events\Auth;

use App\Models\Auth\Passkey;

class PasskeyUsedToAuthenticateEvent
{
    /**
     * Create a new event instance.
     */
    public function __construct(
        public Passkey $passkey,
    )
    {
        //
    }
}
