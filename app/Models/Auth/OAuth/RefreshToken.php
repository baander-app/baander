<?php

declare(strict_types=1);

namespace App\Models\Auth\OAuth;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends BaseModel
{
    protected $table = 'oauth_refresh_tokens';

    protected $fillable = [
        'token_id', // OAuth server identifier
        'access_token_id',
        'revoked',
        'expires_at',
        'chain_id',
        'previous_refresh_token_id',
        'used_at',
    ];

    protected $casts = [
        'revoked' => 'boolean',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(Token::class, 'access_token_id');
    }

    public function revoke(): void
    {
        $this->update(['revoked' => true]);
    }

    public function isRevoked(): bool
    {
        return $this->revoked || $this->expires_at->isPast();
    }

    /**
     * Check if this refresh token has already been used
     */
    public function wasUsed(): bool
    {
        return $this->used_at !== null;
    }
}
