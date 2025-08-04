<?php

namespace Database\Factories;

use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Song>
 */
class SongFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'path' => $this->faker->filePath(),
            'track' => $this->faker->numberBetween(1, 20),
            'duration' => $this->faker->numberBetween(30, 300),
        ];
    }
}
