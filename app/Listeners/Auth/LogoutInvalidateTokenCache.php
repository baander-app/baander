<?php

namespace App\Listeners\Auth;

use App\Models\PersonalAccessToken;
use Illuminate\Auth\Events\Logout;

class LogoutInvalidateTokenCache
{
    /**
     * Handle the event.
     */
    public function handle(Logout $event): void
    {
        if (!config('sanctum.invalidate_on_logout')) {
            return;
        }

        // Revoke current token if using API
        $currentToken = $event->user->currentAccessToken();

        if ($currentToken) {
            // Remove from cache first
            PersonalAccessToken::invalidateTokenCache($currentToken->id);

            // Then delete from database
            $currentToken->delete();
        }
    }
}
