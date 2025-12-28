<?php

namespace App\Modules\Auth;

use App\Models\Auth\ThirdPartyCredential;
use App\Models\User;
use DateTime;
use Illuminate\Support\Collection;

class ThirdPartyCredentialService
{
    public function storeCredential(
        User      $user,
        string    $provider,
        array     $metaData,
        ?DateTime $expiresAt = null
    ): ThirdPartyCredential {
        return (new \App\Models\Auth\ThirdPartyCredential)->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
            ],
            [
                'meta' => $metaData,
                'expires_at' => $expiresAt,
            ]
        );
    }

    public function updateCredentialMeta(
        User $user,
        string $provider,
        array $metaData
    ): ?ThirdPartyCredential {
        $credential = $user->getThirdPartyCredential($provider);

        if (!$credential) {
            return null;
        }

        $credential->mergeMeta($metaData);
        $credential->save();

        return $credential;
    }

    public function getCredentialMeta(User $user, string $provider, string $key, $default = null)
    {
        $credential = $user->getThirdPartyCredential($provider);

        return $credential ? $credential->getMeta($key, $default) : $default;
    }

    public function setCredentialMeta(User $user, string $provider, string $key, $value): bool
    {
        $credential = $user->getThirdPartyCredential($provider);

        if (!$credential) {
            return false;
        }

        $credential->setMeta($key, $value);
        $credential->save();

        return true;
    }

    public function getCredential(User $user, string $provider): ?ThirdPartyCredential
    {
        return $user->getThirdPartyCredential($provider);
    }

    public function removeCredential(User $user, string $provider): bool
    {
        return (new \App\Models\Auth\ThirdPartyCredential)->where('user_id', $user->id)
                ->where('provider', $provider)
                ->delete() > 0;
    }

    public function getUserConnectedProviders(User $user): Collection
    {
        return $user->thirdPartyCredentials()
            ->valid()
            ->pluck('provider');
    }

    public function isProviderConnected(User $user, string $provider): bool
    {
        return $user->hasValidCredential($provider);
    }

    public function getExpiredCredentials(): Collection
    {
        return (new \App\Models\Auth\ThirdPartyCredential)->where('expires_at', '<', now())
            ->whereNotNull('expires_at')
            ->get();
    }

    public function cleanupExpiredCredentials(): int
    {
        return (new \App\Models\Auth\ThirdPartyCredential)->where('expires_at', '<', now())
            ->whereNotNull('expires_at')
            ->delete();
    }
}