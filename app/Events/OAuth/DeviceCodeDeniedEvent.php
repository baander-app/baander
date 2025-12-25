<?php

namespace App\Events\OAuth;

use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\DeviceCode;
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
