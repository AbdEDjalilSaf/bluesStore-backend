<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_categories_public(): void
    {
        Category::factory()->count(3)->create();

        $response = $this->getJson('/api/categories');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_list_categories_returns_empty_array_when_none(): void
    {
        $response = $this->getJson('/api/categories');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_can_create_category(): void
    {
        $response = $this->postJson('/api/categories', [
            'name' => 'Club Jerseys',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Club Jerseys');
        $this->assertDatabaseHas('categories', ['name' => 'Club Jerseys']);
    }

    public function test_create_category_requires_unique_name(): void
    {
        Category::factory()->create(['name' => 'Club Jerseys']);

        $response = $this->postJson('/api/categories', [
            'name' => 'Club Jerseys',
        ]);

        $response->assertStatus(422);
    }

    public function test_create_category_validates_required_name(): void
    {
        $response = $this->postJson('/api/categories', []);

        $response->assertStatus(422);
    }

    public function test_can_show_category_public(): void
    {
        $category = Category::factory()->create();

        $response = $this->getJson("/api/categories/{$category->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $category->id);
    }

    public function test_show_returns_404_when_not_found(): void
    {
        $response = $this->getJson('/api/categories/999');

        $response->assertNotFound();
    }

    public function test_can_update_category_name(): void
    {
        $category = Category::factory()->create(['name' => 'Old Name']);

        $response = $this->patchJson("/api/categories/{$category->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'New Name']);
    }

    public function test_update_validates_unique_name_ignoring_self(): void
    {
        $a = Category::factory()->create(['name' => 'A']);
        $b = Category::factory()->create(['name' => 'B']);

        $response = $this->patchJson("/api/categories/{$b->id}", ['name' => 'A']);
        $response->assertStatus(422);

        $response = $this->patchJson("/api/categories/{$b->id}", ['name' => 'B']);
        $response->assertOk();
    }

    public function test_update_returns_404_when_not_found(): void
    {
        $response = $this->patchJson('/api/categories/999', ['name' => 'X']);

        $response->assertNotFound();
    }

    public function test_can_delete_category_when_no_products(): void
    {
        $category = Category::factory()->create();

        $response = $this->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_category_when_has_products(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create(['category_id' => $category->id]);

        $response = $this->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_delete_returns_404_when_not_found(): void
    {
        $response = $this->deleteJson('/api/categories/999');

        $response->assertNotFound();
    }
}
