<?php

namespace App\Models;

use DateTimeInterface;
use DeviceDetector\DeviceDetector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\{HasApiTokens, NewAccessToken};
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements WebAuthnAuthenticatable
{
    use HasFactory,
        HasApiTokens,
        Notifiable,
        TwoFactorAuthenticatable,
        WebAuthnAuthentication;

    protected $dateFormat = 'Y-m-d H:i:sO';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'is_admin',
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

    public function isAdmin()
    {
        return $this->is_admin;
    }

    /**
     * Create a new personal access token for the user.
     *
     * @param string $name
     * @param DeviceDetector $deviceDetector
     * @param array $abilities
     * @param \DateTimeInterface|null $expiresAt
     * @return NewAccessToken
     */
    public function createToken(string $name, array $abilities = ['*'], DateTimeInterface $expiresAt = null, array $device = [])
    {
        $plainTextToken = $this->generateTokenString();

        $attributes = [
            'name'       => $name,
            'token'      => hash('sha256', $plainTextToken),
            'abilities'  => $abilities,
            'expires_at' => $expiresAt,
        ];

        $attributes += $device;

        $token = $this->tokens()->create($attributes);

        return new NewAccessToken($token, $token->getKey() . '|' . $plainTextToken);
    }

    public function userMediaActivities()
    {
        return $this->hasMany(UserMediaActivity::class);
    }
}
