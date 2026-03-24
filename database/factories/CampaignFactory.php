<?php

namespace Database\Factories;

use App\Enums\AdvertisingPlatform;
use App\Enums\CampaignPhase;
use App\Models\Brand;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
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
            'name' => fake()->sentence(3),
            'campaign_id' => fake()->numerify('##############'),
            'advertising_platform' => AdvertisingPlatform::Meta,
            'phase' => CampaignPhase::Phase2,
        ];
    }
}
