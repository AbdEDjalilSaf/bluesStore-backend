<?php

namespace Database\Factories;

use App\Models\Wilaya;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wilaya>
 */
class WilayaFactory extends Factory
{
    protected $model = Wilaya::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'phone_number' => fake()->numerify('###'),
            'shipping_fee' => fake()->randomElement([400, 500, 600, 700, 800]),
            'is_active' => true,
        ];
    }
}
