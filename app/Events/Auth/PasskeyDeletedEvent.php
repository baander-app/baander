<?php

namespace App\Events\Auth;

use App\Models\Auth\Passkey;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PasskeyDeletedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Passkey $passkey,
        public User $user,
        public string $credentialId,
    ) {
        //
    }
}
