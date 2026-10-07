<?php

namespace Tests\Feature;

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

    public function test_index_filters_by_team(): void
    {
        Product::factory()->create([
            'name' => 'Algeria Home',
            'slug' => 'algeria-home',
            'team' => 'Algeria',
        ]);
        Product::factory()->create([
            'name' => 'Brazil Away',
            'slug' => 'brazil-away',
            'team' => 'Brazil',
        ]);

        $this->getJson('/api/products?team=Algeria')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'algeria-home');

        $this->getJson('/api/products?team=Algeria&search=brazil')
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
        $this->getJson('/api/products?per_page=0')->assertStatus(422);
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
}
