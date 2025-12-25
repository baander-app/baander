<?php

declare(strict_types=1);

namespace App\Models\Auth\OAuth;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthCode extends BaseModel
{
    protected $table = 'oauth_auth_codes';

    protected $fillable = [
        'code_id', // OAuth server identifier
        'user_id',
        'client_id',
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

    public function revoke(): void
    {
        $this->update(['revoked' => true]);
    }

    public function isRevoked(): bool
    {
        return $this->revoked || $this->expires_at->isPast();
    }
}
