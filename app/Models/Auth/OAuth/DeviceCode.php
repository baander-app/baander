<?php

declare(strict_types=1);

namespace App\Models\Auth\OAuth;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCode extends BaseModel
{
    protected $table = 'oauth_device_codes';

    protected $fillable = [
        'user_id',
        'client_id',
        'device_code',
        'user_code',
        'scopes',
        'verification_uri',
        'verification_uri_complete',
        'expires_at',
        'interval',
        'last_polled_at',
        'approved',
        'denied',
    ];

    protected $casts = [
        'scopes'         => 'array',
        'expires_at'     => 'datetime',
        'last_polled_at' => 'datetime',
        'approved'       => 'boolean',
        'denied'         => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function approve(User $user): void
    {
        $this->update([
            'user_id'  => $user->id,
            'approved' => true,
            'denied'   => false,
        ]);
    }

    public function deny(): void
    {
        $this->update([
            'approved' => false,
            'denied'   => true,
        ]);
    }

    public function isPending(): bool
    {
        return !$this->approved && !$this->denied && !$this->isExpired();
    }

    public function updateLastPolled(): void
    {
        $this->update(['last_polled_at' => now()]);
    }
}
