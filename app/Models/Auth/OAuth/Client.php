<?php

declare(strict_types=1);

namespace App\Models\Auth\OAuth;

use App\Models\BaseModel;
use App\Modules\Nanoid\Concerns\HasNanoPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use League\OAuth2\Server\Entities\ClientEntityInterface;

class Client extends BaseModel implements ClientEntityInterface
{
    use HasFactory, HasNanoPublicId;

    protected $table = 'oauth_clients';

    protected $fillable = [
        'public_id',
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
        'password_client'        => 'boolean',
        'device_client'          => 'boolean',
        'revoked'                => 'boolean',
        'confidential'           => 'boolean',
        'first_party'            => 'boolean',
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

    public function getIdentifier(): string
    {
        return $this->public_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRedirectUri(): string|array
    {
        return $this->redirect;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    public function isFirstParty(): bool
    {
        return $this->first_party;
    }

    protected function scopeWhereFirstParty()
    {
        return $this->where('first_party', true);
    }
}
