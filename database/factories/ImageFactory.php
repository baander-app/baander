<?php

namespace Database\Factories;

use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Image>
 */
class ImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'path' => $this->faker->filePath(),
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => $this->faker->numberBetween(1000, 50000),
            'width' => 500,
            'height' => 500,
        ];
    }
}
