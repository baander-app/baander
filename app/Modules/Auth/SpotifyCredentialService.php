<?php

namespace App\Modules\Auth;

use App\Models\User;

class SpotifyCredentialService
{
    public function __construct(
        private readonly ThirdPartyCredentialService $thirdPartyCredentialService
    ) {
    }

    /**
     * Get the access token for a user
     */
    public function getAccessToken(User $user): ?string
    {
        return $this->thirdPartyCredentialService->getCredential($user, 'spotify', 'access_token');
    }

    /**
     * Get the refresh token for a user
     */
    public function getRefreshToken(User $user): ?string
    {
        return $this->thirdPartyCredentialService->getCredential($user, 'spotify', 'refresh_token');
    }

    /**
     * Check if user has valid Spotify credentials
     */
    public function hasValidCredentials(User $user): bool
    {
        return !empty($this->getAccessToken($user));
    }

    /**
     * Store Spotify credentials for a user
     */
    public function storeCredentials(User $user, string $accessToken, string $refreshToken, ?int $expiresIn = null): void
    {
        $this->thirdPartyCredentialService->storeCredential($user, 'spotify', 'access_token', $accessToken);
        $this->thirdPartyCredentialService->storeCredential($user, 'spotify', 'refresh_token', $refreshToken);

        if ($expiresIn) {
            $this->thirdPartyCredentialService->storeCredential($user, 'spotify', 'expires_at', (string)(time() + $expiresIn));
        }
    }

    /**
     * Remove Spotify credentials for a user
     */
    public function removeCredentials(User $user): void
    {
        $this->thirdPartyCredentialService->removeCredential($user, 'spotify', 'access_token');
        $this->thirdPartyCredentialService->removeCredential($user, 'spotify', 'refresh_token');
        $this->thirdPartyCredentialService->removeCredential($user, 'spotify', 'expires_at');
    }

    /**
     * Check if access token is expired
     */
    public function isTokenExpired(User $user): bool
    {
        $expiresAt = $this->thirdPartyCredentialService->getCredential($user, 'spotify', 'expires_at');

        if (!$expiresAt) {
            return false;
        }

        return time() >= (int)$expiresAt;
    }
}