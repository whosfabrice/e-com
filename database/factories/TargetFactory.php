<?php

namespace Database\Factories;

use App\Enums\AdvertisingPlatform;
use App\Enums\TargetMetric;
use App\Models\Brand;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Target>
 */
class TargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'platform' => AdvertisingPlatform::Meta,
            'metric' => TargetMetric::Cpa,
            'value' => fake()->randomFloat(2, 10, 100),
        ];
    }
}
