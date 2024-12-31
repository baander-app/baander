<?php

namespace App\Services;

use App\Models\PersonalAccessToken;

class AuthTokenService
{
    public function pruneExpiredTokens()
    {
        return PersonalAccessToken::whereExpired()->delete();
    }

    public function getExpiredTokenCount()
    {
        return PersonalAccessToken::whereExpired()->count();
    }

    public function revokeToken(string $token): bool
    {
        $model = PersonalAccessToken::whereToken($token)->firstOrFail();

        return $model->update([
            'expires_at' => now(),
        ]);
    }
}