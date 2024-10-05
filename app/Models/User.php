<?php

namespace App\Models;

use App\Auth\Role;
use App\Models\Player\PlayerQueue;
use App\Models\Player\PlayerState;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\{HasApiTokens, NewAccessToken};
use Ramsey\Uuid\Uuid;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory,
        HasApiTokens,
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

    /**
     * Create a new personal access token for the user.
     *
     * @param string $name
     * @param array $abilities
     * @param \DateTimeInterface|null $expiresAt
     * @param array $device
     * @return NewAccessToken
     */
    public function createToken(string $name, array $abilities = ['*'], DateTimeInterface $expiresAt = null, array $device = [])
    {
        $plainTextToken = $this->generateTokenString();
        $broadcastToken = Str::replace('-', '', Uuid::uuid4()->toString());

        $attributes = [
            'name'            => $name,
            'token'           => hash('sha256', $plainTextToken),
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

    public function accessibleLibraries()
    {
        return $this->belongsToMany(Library::class)
            ->using(UserLibrary::class);
    }

    public function playerStates()
    {
        return $this->hasMany(PlayerState::class);
    }

    public function playerQueues()
    {
        return $this->hasMany(PlayerQueue::class);
    }

    public function userMediaActivities()
    {
        return $this->hasMany(UserMediaActivity::class);
    }
}
