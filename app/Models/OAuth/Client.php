<?php

declare(strict_types=1);

namespace App\Models\OAuth;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends BaseModel
{
    use HasUuids;

    protected $table = 'oauth_clients';

    protected $fillable = [
        'name',
        'secret',
        'provider',
        'redirect',
        'personal_access_client',
        'password_client',
        'device_client',
        'revoked',
        'confidential',
        'first_party',
    ];

    protected $casts = [
        'personal_access_client' => 'boolean',
        'password_client' => 'boolean',
        'device_client' => 'boolean',
        'revoked' => 'boolean',
        'confidential' => 'boolean',
        'first_party' => 'boolean',
    ];

    protected $hidden = [
        'secret',
    ];

    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    public function authCodes(): HasMany
    {
        return $this->hasMany(AuthCode::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function deviceCodes(): HasMany
    {
        return $this->hasMany(DeviceCode::class);
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    public function isFirstParty(): bool
    {
        return $this->first_party;
    }
}
