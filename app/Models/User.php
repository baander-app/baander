<?php

namespace App\Models;

use App\Auth\Role;
use App\Models\Auth\OAuth\Token as OAuthToken;
use App\Models\Auth\Passkey;
use App\Models\Auth\ThirdPartyCredential;
use App\Modules\Auth\Webauthn\Concerns\HasPasskeys;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasPasskeys
{
    use HasFactory,
        HasNanoPublicId,
        HasRoles,
        Notifiable;

    protected $dateFormat = 'Y-m-d H:i:sO';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'oauth'             => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Get all OAuth tokens for the user.
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(OAuthToken::class, 'user_id');
    }

    /**
     * Get the current access token being used.
     */
    public function currentAccessToken(): ?OAuthToken
    {
        // Implementation depends on how the OAuth guard tracks current token
        // The guard should provide this
        return null;
    }

    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    public function isAdmin()
    {
        return $this->hasRole(Role::Admin->value);
    }

    public function thirdPartyCredentials(): HasMany
    {
        return $this->hasMany(ThirdPartyCredential::class);
    }

    public function getThirdPartyCredential(string $provider): ?ThirdPartyCredential
    {
        return $this->thirdPartyCredentials()
            ->forProvider($provider)
            ->valid()
            ->first();
    }

    public function hasValidCredential(string $provider): bool
    {
        return $this->getThirdPartyCredential($provider) !== null;
    }

    // Provider-specific helpers
    public function getLastFmCredential(): ?ThirdPartyCredential
    {
        return $this->getThirdPartyCredential('lastfm');
    }

    public function getSpotifyCredential(): ?ThirdPartyCredential
    {
        return $this->getThirdPartyCredential('spotify');
    }

    public function getDiscogsCredential(): ?ThirdPartyCredential
    {
        return $this->getThirdPartyCredential('discogs');
    }

    public function getMusicBrainzCredential(): ?ThirdPartyCredential
    {
        return $this->getThirdPartyCredential('musicbrainz');
    }

    // Helper methods for common operations
    public function getConnectedProviders(): array
    {
        return $this->thirdPartyCredentials()
            ->valid()
            ->pluck('provider')
            ->toArray();
    }

    public function isConnectedTo(string $provider): bool
    {
        return in_array($provider, $this->getConnectedProviders());
    }

    public function getProviderUsername(string $provider): ?string
    {
        $credential = $this->getThirdPartyCredential($provider);
        return $credential?->getProviderUsername();
    }

    public function getProviderMeta(string $provider, string $key, $default = null)
    {
        $credential = $this->getThirdPartyCredential($provider);
        return $credential?->getMeta($key, $default);
    }


    public function accessibleLibraries()
    {
        return $this->belongsToMany(Library::class)
            ->using(UserLibrary::class);
    }

    public function userMediaActivities()
    {
        return $this->hasMany(UserMediaActivity::class);
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    public function getPassKeyName(): string
    {
        return $this->email;
    }

    public function getPassKeyId(): string
    {
        return $this->id;
    }

    public function getPassKeyDisplayName(): string
    {
        return $this->name;
    }
}
