<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = strtoupper(fake()->unique()->lexify('?????'));

        return [
            'name' => $name,
            'handle' => Str::slug($name),
            'meta_ad_account_id' => fake()->numerify('################'),
            'slack_channel_id' => fake()->regexify('C[0-9A-Z]{10}'),
        ];
    }
}
