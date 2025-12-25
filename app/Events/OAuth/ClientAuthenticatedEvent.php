<?php

namespace App\Events\OAuth;

use App\Models\Auth\OAuth\Client;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;

class ClientAuthenticatedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Client $client,
        public ?Request $request = null,
    ) {
        $this->request ??= request();
    }

    /**
     * Get the client's IP address.
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
