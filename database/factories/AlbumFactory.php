<?php

namespace Database\Factories;

use App\Models\Album;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Album>
 */
class AlbumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'library_id' => \App\Models\Library::factory(),
            'title' => $this->faker->words(3, true),
            'type' => 'studio',
            'year' => $this->faker->year(),
            'label' => $this->faker->company(),
            'catalog_number' => $this->faker->bothify('CAT-????'),
            'barcode' => $this->faker->numerify('#############'),
            'country' => $this->faker->countryCode(),
            'language' => 'eng',
        ];
    }
}
