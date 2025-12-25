<?php

namespace App\Events\Auth;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

class PasskeyAuthenticationFailedEvent
{
    use Dispatchable;

    /**
     * Reason for authentication failure.
     */
    public string $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public ?Request $request = null,
        ?string $reason = null,
    ) {
        $this->request ??= request();
        $this->reason ??= $reason ?? 'unknown';
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
