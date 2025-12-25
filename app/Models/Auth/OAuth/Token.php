<?php

declare(strict_types=1);

namespace App\Models\Auth\OAuth;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Token extends BaseModel
{
    protected $table = 'oauth_access_tokens';

    protected $fillable = [
        'token_id', // OAuth server identifier
        'user_id',
        'client_id',
        'name',
        'scopes',
        'revoked',
        'expires_at',
        'chain_id',
        'last_refreshed_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'revoked' => 'boolean',
        'expires_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'access_token_id');
    }

    public function metadata(): HasOne
    {
        return $this->hasOne(TokenMetadata::class, 'token_id', 'token_id');
    }

    public function revoke(): void
    {
        $this->update(['revoked' => true]);
    }

    public function isRevoked(): bool
    {
        return $this->revoked || $this->expires_at->isPast();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /**
     * Compatibility method from Sanctum - check if token has ability (scope)
     */
    public function can(string $ability): bool
    {
        return $this->hasScope($ability);
    }

    /**
     * Check if token has device binding (first-party token with security)
     */
    public function hasDeviceBinding(): bool
    {
        return $this->metadata && $this->metadata->client_fingerprint !== null;
    }

    /**
     * Revoke all tokens in this chain (security measure for token reuse detection)
     */
    public function revokeChain(): void
    {
        if (!$this->chain_id) {
            return;
        }

        // Revoke all access tokens in the chain
        Token::where('chain_id', $this->chain_id)->update(['revoked' => true]);

        // Revoke all refresh tokens in the chain
        RefreshToken::where('chain_id', $this->chain_id)->update(['revoked' => true]);
    }
}
