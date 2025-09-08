<?php

declare(strict_types=1);

namespace App\Models\OAuth;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Token extends BaseModel
{
    use HasUuids;

    protected $table = 'oauth_access_tokens';

    protected $fillable = [
        'token_id', // OAuth server identifier
        'user_id',
        'client_id',
        'name',
        'scopes',
        'revoked',
        'expires_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'revoked' => 'boolean',
        'expires_at' => 'datetime',
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
}
