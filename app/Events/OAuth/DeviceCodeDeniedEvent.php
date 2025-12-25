<?php

namespace App\Events\OAuth;

use App\Models\OAuth\Client;
use App\Models\OAuth\DeviceCode;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class DeviceCodeDeniedEvent
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public DeviceCode $deviceCode,
        public User $user,
        public Client $client,
    ) {
        //
    }
}
