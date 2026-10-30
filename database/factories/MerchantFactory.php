<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'currency' => 'INR',
            'api_key_hash' => hash('sha256', Str::random(40)),
        ];
    }
}
