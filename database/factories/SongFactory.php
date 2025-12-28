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
            'size' => $this->faker->numberBetween(1024 * 1024, 50 * 1024 * 1024), // 1MB to 50MB
            'mime_type' => 'audio/mpeg',
            'track' => $this->faker->numberBetween(1, 20),
            'length' => $this->faker->numberBetween(30, 300),
        ];
    }
}
