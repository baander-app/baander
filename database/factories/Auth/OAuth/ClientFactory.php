<?php

namespace Database\Factories\Auth\OAuth;

use App\Models\Auth\OAuth\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'secret' => $this->faker->password(64, 64),
            'provider' => null,
            'redirect' => $this->faker->url(),
            'personal_access_client' => false,
            'password_client' => false,
            'device_client' => false,
            'confidential' => true,
            'first_party' => false,
            'revoked' => false,
        ];
    }
}
