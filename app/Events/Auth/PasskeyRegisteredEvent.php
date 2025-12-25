<?php

namespace App\Events\Auth;

use App\Models\Auth\Passkey;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

class PasskeyRegisteredEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Passkey $passkey,
        public User $user,
        public ?string $name = null,
        public ?Request $request = null,
    ) {
        $this->request ??= request();
    }

    /**
     * Get the user's IP address.
     */
    public function ipAddress(): ?string
    {
        return $this->request?->ip();
    }

    /**
     * Get the user agent.
     */
    public function userAgent(): ?string
    {
        return $this->request?->userAgent();
    }
}
