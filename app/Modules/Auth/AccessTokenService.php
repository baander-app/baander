<?php

namespace App\Modules\Auth;

use App\Models\PersonalAccessToken;

class AccessTokenService
{
    public function pruneExpiredTokens()
    {
        return (new \App\Models\PersonalAccessToken)->whereExpired()->delete();
    }

    public function getExpiredTokenCount()
    {
        return (new \App\Models\PersonalAccessToken)->whereExpired()->count();
    }

    public function revokeToken(string $token): bool
    {
        $model = (new \App\Models\PersonalAccessToken)->whereToken($token)->firstOrFail();

        return $model->update([
            'expires_at' => now(),
        ]);
    }
}