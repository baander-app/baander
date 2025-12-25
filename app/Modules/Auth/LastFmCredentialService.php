<?php

namespace App\Modules\Auth;

use App\Models\Auth\ThirdPartyCredential;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;

class LastFmCredentialService
{
    public function __construct(
        private readonly ThirdPartyCredentialService $credentialService
    ) {}

    /**
     * Get valid Last.fm credentials for a user
     */
    public function getCredentials(User $user): ?ThirdPartyCredential
    {
        return $this->credentialService->getCredential($user, 'lastfm');
    }

    /**
     * Get Last.fm session key for a user
     */
    public function getSessionKey(User $user): ?string
    {
        $credential = $this->getCredentials($user);
        return $credential?->getSessionKey();
    }

    /**
     * Check if user has valid Last.fm credentials
     * Note: This only checks if credentials exist, not if they're valid with Last.fm API
     */
    public function hasCredentials(User $user): bool
    {
        $credential = $this->getCredentials($user);
        return $credential && $credential->getSessionKey();
    }

    /**
     * Check if user has valid Last.fm credentials by validating with API
     * This method should be called from LastFmClient to avoid circular dependency
     */
    public function hasValidCredentials(User $user, ?callable $validator = null): bool
    {
        $credential = $this->getCredentials($user);

        if (!$credential || !$credential->getSessionKey()) {
            return false;
        }

        // If validator is provided, use it to validate with API
        if ($validator) {
            $isValid = $validator($credential->getSessionKey());

            if (!$isValid) {
                // Remove invalid credentials
                $credential->delete();
                return false;
            }
        }

        return true;
    }

    /**
     * Get Last.fm user data
     */
    public function getUserData(User $user): array
    {
        $credential = $this->getCredentials($user);
        return $credential?->getLastFmData() ?? [];
    }

    /**
     * Update user's Last.fm profile data
     */
    public function updateUserData(User $user, array $userData): bool
    {
        $credential = $this->getCredentials($user);

        if (!$credential) {
            return false;
        }

        try {
            $credential->mergeMeta($userData);
            $credential->save();
            return true;
        } catch (Exception $e) {
            Log::error('Failed to update Last.fm user data', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Remove Last.fm credentials for a user
     */
    public function disconnect(User $user): bool
    {
        return $this->credentialService->removeCredential($user, 'lastfm');
    }

    /**
     * Get Last.fm username for a user
     */
    public function getUsername(User $user): ?string
    {
        $credential = $this->getCredentials($user);
        return $credential?->getProviderUsername();
    }
}