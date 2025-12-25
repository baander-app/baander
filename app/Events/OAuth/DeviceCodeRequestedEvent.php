<?php

namespace App\Events\OAuth;

use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\DeviceCode;
use Illuminate\Foundation\Events\Dispatchable;

class DeviceCodeRequestedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public DeviceCode $deviceCode,
        public Client $client,
        public string $deviceCodeString,
        public string $userCode,
        public array $scopes = [],
    ) {
        //
    }
}
