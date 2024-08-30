<?php

namespace Database\Factories;

use App\Models\UserTwoFactorAuthentication;
use App\Packages\TwoFactor\TwoFactorService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserTwoFactorAuthentication>
 */
class UserTwoFactorAuthenticationFactory extends Factory
{
    protected $model = UserTwoFactorAuthentication::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var TwoFactorService $service */
        $service = app(TwoFactorService::class);
        $config = config('two-factor');

        $data = array_merge([
            'shared_secret' => $service->generateSecret(),
            'enabled_at'    => $this->faker->dateTimeBetween('-1 years'),
            'label'         => config('two-factor.issuer') . ':' . $this->faker->freeEmail,
        ], $config['totp']);

        [$enabled, $amount, $length] = array_values($config['recovery']);

        if ($enabled) {
            $data['recovery_codes'] = UserTwoFactorAuthentication::generateRecoveryCodes($amount, $length);
            $data['recovery_codes_generated_at'] = $this->faker->dateTimeBetween('-1 year');
        }

        return $data;
    }

    /**
     * Returns the user with recovery codes.
     *
     * @return static
     */
    public function withRecovery(): static
    {
        [
            'two-factor.recovery.codes' => $amount,
            'two-factor.recovery.length' => $length
        ] = config()->get(['two-factor.recovery.codes', 'two-factor.recovery.length']);

        return $this->state([
            'recovery_codes' => UserTwoFactorAuthentication::generateRecoveryCodes($amount, $length),
            'recovery_codes_generated_at' => $this->faker->dateTimeBetween('-1 years'),
        ]);
    }

    /**
     * Returns an authentication with a list of safe devices.
     */
    public function withSafeDevices(): static
    {
        $max = config('two-factor.safe_devices.max_devices');

        return $this->state([
            'safe_devices' => Collection::times($max, function ($step) use ($max) {
                $expiration_days = config('two-factor.safe_devices.expiration_days');

                $added_at = $max !== $step
                    ? now()
                    : $this->faker->dateTimeBetween(now()->subDays($expiration_days * 2),
                        now()->subDays($expiration_days));

                return [
                    '2fa_remember' => UserTwoFactorAuthentication::generateDefaultTwoFactorRemember(),
                    'ip' => $this->faker->ipv4,
                    'added_at' => $added_at,
                ];
            }),
        ]);
    }

    /**
     * Returns an enabled authentication.
     */
    public function enabled(): static
    {
        return $this->state([
            'enabled_at' => null,
        ]);
    }
}
