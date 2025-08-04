<?php

namespace App\Models;

use App\Auth\Role;
use App\Modules\Auth\Webauthn\Concerns\HasPasskeys;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\{HasApiTokens, NewAccessToken};
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasPasskeys
{
    use HasFactory,
        HasApiTokens,
        HasNanoPublicId,
        HasRoles,
        Notifiable,
        TwoFactorAuthenticatable;

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
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Create a new personal access token for the user.
     *
     * @param string $name
     * @param array $abilities
     * @param DateTimeInterface|null $expiresAt
     * @param array $device
     * @return NewAccessToken
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null, array $device = [])
    {
        $plainTextToken = $this->generateTokenString();
        $broadcastToken = Str::replace('-', '', Uuid::uuid4()->toString());

        $attributes = [
            'name'            => $name,
            'token'           => hash('xxh3', $plainTextToken),
            'broadcast_token' => $broadcastToken,
            'abilities'       => $abilities,
            'expires_at'      => $expiresAt,
        ];

        $attributes += $device;

        $token = $this->tokens()->create($attributes);

        return new NewAccessToken($token, $token->getKey() . '|' . $plainTextToken);
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
