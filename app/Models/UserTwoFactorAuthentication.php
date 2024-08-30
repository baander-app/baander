<?php

namespace App\Models;

use App\Packages\TwoFactor\Concerns\{HandlesCodes, HandlesRecoveryCodes, HandlesSafeDevices, SerializesSharedSecret};
use App\Packages\TwoFactor\Contracts\TwoFactorTotp;
use Database\Factories\UserTwoFactorAuthenticationFactory;
use ParagonIE\ConstantTime\Base32;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserTwoFactorAuthentication extends BaseModel implements TwoFactorTotp
{
    use HandlesCodes,
        HandlesRecoveryCodes,
        HandlesSafeDevices,
        SerializesSharedSecret,
        HasFactory;

    protected $fillable = [
        'digits',
        'seconds',
        'window',
        'algorithm',
    ];

    protected $casts = [
        'shared_secret'               => 'encrypted',
        'digits'                      => 'int',
        'seconds'                     => 'int',
        'window'                      => 'int',
        'recovery_codes'              => 'encrypted:collection',
        'safe_devices'                => 'collection',
        'enabled_at'                  => 'datetime',
        'recovery_codes_generated_at' => 'datetime',
    ];

    /**
     * @inheritDoc
     */
    protected static function newFactory()
    {
        return new UserTwoFactorAuthenticationFactory();
    }

    /**
     * The model that uses Two-Factor Authentication.
     */
    public function authenticatable()
    {
        return $this->morphTo('authenticatable');
    }

    /**
     * Sets the Algorithm to lowercase.
     */
    protected function setAlgorithmAttribute(string $value): void
    {
        $this->attributes['algorithm'] = strtolower($value);
    }

    /**
     * Returns if the Two-Factor Authentication has been enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }

    /**
     * Returns if the Two-Factor Authentication is not enabled.
     */
    public function isDisabled(): bool
    {
        return ! $this->isEnabled();
    }

    /**
     * Flushes all authentication data and cycles the Shared Secret.
     *
     * @return $this
     */
    public function flushAuth(): static
    {
        $this->recovery_codes_generated_at = null;
        $this->safe_devices = null;
        $this->enabled_at = null;

        $this->attributes = array_merge($this->attributes, config('two-factor.totp'));

        $this->shared_secret = static::generateRandomSecret();
        $this->recovery_codes = null;

        return $this;
    }

    /**
     * Creates a new Random Secret.
     */
    public static function generateRandomSecret(): string
    {
        return Base32::encodeUpper(
            random_bytes(config('two-factor.secret_length'))
        );
    }

    /**
     * Convert the model instance to JSON.
     *
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toUri(), $options);
    }

}
