<?php

declare(strict_types=1);

namespace App\Models\Auth\OAuth;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenMetadata extends BaseModel
{
    protected $table = 'token_metadata';

    protected $fillable = [
        'token_id',
        'user_agent',
        'device_operating_system',
        'device_name',
        'client_fingerprint',
        'session_id',
        'ip_address',
        'ip_history',
        'ip_change_count',
        'country_code',
        'city',
        'last_geo_notification_at',
        'broadcast_token',
    ];

    protected $casts = [
        'ip_history' => 'array',
        'last_geo_notification_at' => 'datetime',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Auth\OAuth\Token::class, 'token_id', 'token_id');
    }
}
