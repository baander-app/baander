<?php

namespace Database\Factories;

use App\Models\Library;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Library>
 */
class LibraryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'path' => $this->faker->filePath(),
            'type' => 'music', // Default to music type for tests
            'order' => $this->faker->numberBetween(1, 100),
        ];
    }
}
