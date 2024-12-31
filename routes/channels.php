<?php

use App\Events\ChannelName;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(ChannelName::Notifications->value, function (User $user) {
    return auth()->check();
});