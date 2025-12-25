<?php

namespace App\Models\Auth;

use App\Models\User;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThirdPartyCredential extends Model
{
    use HasNanoPublicId;

    protected $fillable = [
        'user_id',
        'provider',
        'expires_at',
        'meta',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'meta'       => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && $this->hasValidCredentials();
    }

    public function hasValidCredentials(): bool
    {
        return $this->getAccessToken() || $this->getSessionKey();
    }

    // Generic getters for common fields
    public function getAccessToken(): ?string
    {
        return $this->meta['access_token'] ?? null;
    }

    public function getRefreshToken(): ?string
    {
        return $this->meta['refresh_token'] ?? null;
    }

    public function getSessionKey(): ?string
    {
        return $this->meta['session_key'] ?? null;
    }

    public function getProviderUserId(): ?string
    {
        return $this->meta['provider_user_id'] ?? null;
    }

    public function getProviderUsername(): ?string
    {
        return $this->meta['provider_username'] ?? null;
    }

    // Helper to get any meta field
    public function getMeta(string $key, $default = null)
    {
        return $this->meta[$key] ?? $default;
    }

    // Helper to set meta field
    public function setMeta(string $key, $value): void
    {
        $meta = $this->meta ?? [];
        $meta[$key] = $value;
        $this->meta = $meta;
    }

    // Helper to merge meta data
    public function mergeMeta(array $data): void
    {
        $this->meta = array_merge($this->meta ?? [], $data);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    // Provider-specific helpers
    public function getLastFmData(): array
    {
        return [
            'session_key' => $this->getSessionKey(),
            'username'    => $this->getProviderUsername(),
            'playcount'   => $this->getMeta('playcount', 0),
            'registered'  => $this->getMeta('registered'),
            'country'     => $this->getMeta('country'),
            'subscriber'  => $this->getMeta('subscriber', '0'),
            'realname'    => $this->getMeta('realname'),
            'url'         => $this->getMeta('url'),
            'image'       => $this->getMeta('image', []),
        ];
    }

    public function getSpotifyData(): array
    {
        return [
            'access_token'  => $this->getAccessToken(),
            'refresh_token' => $this->getRefreshToken(),
            'username'      => $this->getProviderUsername(),
            'display_name'  => $this->getMeta('display_name'),
            'email'         => $this->getMeta('email'),
            'country'       => $this->getMeta('country'),
            'product'       => $this->getMeta('product'),
            'followers'     => $this->getMeta('followers', 0),
            'images'        => $this->getMeta('images', []),
        ];
    }

    public function getDiscogsData(): array
    {
        return [
            'access_token'    => $this->getAccessToken(),
            'username'        => $this->getProviderUsername(),
            'consumer_key'    => $this->getMeta('consumer_key'),
            'consumer_secret' => $this->getMeta('consumer_secret'),
            'profile'         => $this->getMeta('profile', []),
        ];
    }
}