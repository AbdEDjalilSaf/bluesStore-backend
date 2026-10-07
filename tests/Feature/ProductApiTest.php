<?php

namespace Tests\Feature;

use App\Enums\Condition;
use App\Enums\Rarity;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_active_products_only(): void
    {
        Product::factory()->count(3)->create();
        Product::factory()->create(['name' => 'Hidden Shirt', 'slug' => 'hidden-shirt', 'is_active' => false]);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 12);

        $this->assertNotContains('hidden-shirt', array_column($response->json('data'), 'slug'));
    }

    public function test_index_filters_by_team_rarity_and_condition(): void
    {
        Product::factory()->create([
            'name' => 'Algeria Iconic',
            'slug' => 'algeria-iconic',
            'team' => 'Algeria',
            'rarity' => Rarity::Iconic,
            'condition' => Condition::MintWithTags,
        ]);
        Product::factory()->create([
            'name' => 'Brazil Rare',
            'slug' => 'brazil-rare',
            'team' => 'Brazil',
            'rarity' => Rarity::Rare,
            'condition' => Condition::VeryGood,
        ]);

        $this->getJson('/api/products?team=Algeria')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'algeria-iconic');

        $this->getJson('/api/products?rarity=iconic')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'algeria-iconic');

        $this->getJson('/api/products?condition=mint_with_tags')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'algeria-iconic');

        $this->getJson('/api/products?team=Algeria&rarity=rare')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_search_matches_name_and_team(): void
    {
        Product::factory()->create(['name' => 'Retro Naija Classic', 'slug' => 'retro-naija', 'team' => 'Nigeria']);
        Product::factory()->create(['name' => 'Home Shirt', 'slug' => 'home-shirt', 'team' => 'Algeria']);

        $this->getJson('/api/products?search=naija')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'retro-naija');

        $this->getJson('/api/products?search=algeria')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'home-shirt');
    }

    public function test_index_sorts_by_price_and_year(): void
    {
        Product::factory()->create(['name' => 'Cheap', 'slug' => 'cheap', 'price' => 1000, 'year' => 1990]);
        Product::factory()->create(['name' => 'Mid', 'slug' => 'mid', 'price' => 5000, 'year' => 2020]);
        Product::factory()->create(['name' => 'Expensive', 'slug' => 'expensive', 'price' => 9000, 'year' => 2005]);

        $this->getJson('/api/products?sort=price_asc')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'cheap')
            ->assertJsonPath('data.1.slug', 'mid')
            ->assertJsonPath('data.2.slug', 'expensive');

        $this->getJson('/api/products?sort=price_desc')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'expensive')
            ->assertJsonPath('data.2.slug', 'cheap');

        $this->getJson('/api/products?sort=year')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'mid')
            ->assertJsonPath('data.2.slug', 'cheap');

        $newest = Product::factory()->create(['name' => 'Newest', 'slug' => 'newest']);

        $this->getJson('/api/products?sort=newest')
            ->assertOk()
            ->assertJsonPath('data.0.slug', $newest->slug);
    }

    public function test_index_filters_by_eighties_era_and_sorts_by_price_asc(): void
    {
        Product::factory()->create(['name' => 'Eighties Pricey', 'slug' => 'eighties-pricey', 'year' => 1986, 'price' => 12000]);
        Product::factory()->create(['name' => 'Eighties Bargain', 'slug' => 'eighties-bargain', 'year' => 1983, 'price' => 4000]);
        Product::factory()->create(['name' => 'Seventies', 'slug' => 'seventies', 'year' => 1975, 'price' => 1000]);
        Product::factory()->create(['name' => 'Nineties', 'slug' => 'nineties', 'year' => 1995, 'price' => 2000]);

        $response = $this->getJson('/api/products?year=eighties&sort=price_asc');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'eighties-bargain')
            ->assertJsonPath('data.1.slug', 'eighties-pricey');

        foreach ($response->json('data') as $product) {
            $this->assertGreaterThanOrEqual(1980, $product['year']);
            $this->assertLessThanOrEqual(1989, $product['year']);
        }
    }

    public function test_index_returns_computed_discount_and_stock_fields(): void
    {
        Product::factory()->create([
            'name' => 'Sale Shirt',
            'slug' => 'sale-shirt',
            'price' => 5000,
            'old_price' => 10000,
            'stock' => 3,
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.discount_percent', 50)
            ->assertJsonPath('data.0.in_stock', true)
            ->assertJsonStructure(['data' => [['images']]]);
    }

    public function test_index_rejects_invalid_filters(): void
    {
        $this->getJson('/api/products?rarity=legendary')->assertStatus(422);
        $this->getJson('/api/products?condition=worn_out')->assertStatus(422);
        $this->getJson('/api/products?year=twenties')->assertStatus(422);
        $this->getJson('/api/products?sort=random')->assertStatus(422);
    }

    public function test_index_paginates_with_per_page(): void
    {
        Product::factory()->count(5)->create();

        $this->getJson('/api/products?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_show_returns_product_by_slug(): void
    {
        Product::factory()->create([
            'name' => 'Show Me',
            'slug' => 'show-me',
            'price' => 5000,
            'old_price' => 10000,
            'stock' => 3,
        ]);

        $this->getJson('/api/products/show-me')
            ->assertOk()
            ->assertJsonPath('data.slug', 'show-me')
            ->assertJsonPath('data.discount_percent', 50)
            ->assertJsonPath('data.in_stock', true)
            ->assertJsonStructure(['data' => ['images']]);
    }

    public function test_show_returns_404_for_unknown_or_inactive_slug(): void
    {
        $this->getJson('/api/products/does-not-exist')->assertNotFound();

        Product::factory()->create(['name' => 'Hidden', 'slug' => 'hidden-product', 'is_active' => false]);

        $this->getJson('/api/products/hidden-product')->assertNotFound();
    }

    public function test_year_endpoint_returns_era_options_with_counts(): void
    {
        Product::factory()->create(['name' => 'Eighties A', 'slug' => 'eighties-a', 'year' => 1984]);
        Product::factory()->create(['name' => 'Eighties B', 'slug' => 'eighties-b', 'year' => 1987]);
        Product::factory()->create(['name' => 'Nineties', 'slug' => 'nineties-shirt', 'year' => 1995]);
        Product::factory()->create(['name' => 'Hidden Eighties', 'slug' => 'hidden-eighties', 'year' => 1982, 'is_active' => false]);

        $data = collect($this->getJson('/api/year')->assertOk()->json('data'));

        $this->assertCount(8, $data);
        $this->assertSame(2, $data->firstWhere('value', 'eighties')['count']);
        $this->assertSame(1, $data->firstWhere('value', 'nineties')['count']);
        $this->assertSame(0, $data->firstWhere('value', 'fifties')['count']);
        $this->assertSame('1980s', $data->firstWhere('value', 'eighties')['label']);
        $this->assertSame(['value', 'label', 'count'], array_keys($data->first()));
    }
}
