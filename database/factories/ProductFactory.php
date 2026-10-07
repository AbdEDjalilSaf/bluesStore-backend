<?php

namespace Database\Factories;

use App\Enums\Condition;
use App\Enums\Rarity;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $team = fake()->randomElement([
            'Algeria',
            'Argentina',
            'Brazil',
            'Cameroon',
            'Egypt',
            'England',
            'France',
            'Germany',
            'Ghana',
            'Ivory Coast',
            'Morocco',
            'Netherlands',
            'Nigeria',
            'Portugal',
            'Senegal',
            'Spain',
            'Tunisia',
        ]);
        $year = fake()->numberBetween(1950, 2026);
        $style = fake()->randomElement(['Home', 'Away', 'Third', 'Goalkeeper', 'Training']);
        $name = "{$team} {$year} {$style} Shirt";
        $price = fake()->numberBetween(2000, 20000);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'team' => $team,
            'year' => $year,
            'condition' => fake()->randomElement(Condition::cases()),
            'rarity' => fake()->randomElement(Rarity::cases()),
            'description' => fake()->paragraph(),
            'price' => $price,
            'old_price' => fake()->boolean(70)
                ? (int) round($price * fake()->randomFloat(2, 1.1, 1.6))
                : null,
            'stock' => fake()->numberBetween(0, 25),
            'is_active' => true,
        ];
    }
}
