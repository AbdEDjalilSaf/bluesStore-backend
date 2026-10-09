<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_index_returns_all_active_shirts_without_pagination(): void
    {
        Product::factory()->count(3)->create();
        Product::factory()->create(['name' => 'Hidden Shirt', 'slug' => 'hidden-shirt', 'is_active' => false]);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');

        $this->assertNotContains('hidden-shirt', array_column($response->json('data'), 'slug'));
    }

    public function test_index_ignores_filter_search_sort_and_pagination_params(): void
    {
        Product::factory()->create([
            'name' => 'Algeria Home',
            'slug' => 'algeria-home',
            'team' => 'Algeria',
            'price' => 5000,
        ]);
        Product::factory()->create([
            'name' => 'Brazil Away',
            'slug' => 'brazil-away',
            'team' => 'Brazil',
            'price' => 1000,
        ]);

        $category = Category::factory()->create(['name' => 'Scarves']);

        $response = $this->getJson('/api/products?team=Algeria&search=naija&sort=price_asc&category_id='.$category->id.'&page=2&per_page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('meta');

        $slugs = array_column($response->json('data'), 'slug');

        $this->assertEqualsCanonicalizing(['algeria-home', 'brazil-away'], $slugs);
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

    public function test_show_returns_product_by_id(): void
    {
        $product = Product::factory()->create(['name' => 'By Id', 'slug' => 'by-id']);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.slug', 'by-id');

        $this->getJson('/api/products/999')->assertNotFound();
    }

    public function test_store_creates_product(): void
    {
        $category = Category::factory()->create();

        $response = $this->postJson('/api/products', $this->validProductPayload([
            'category_id' => $category->id,
        ]));

        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'nigeria-1994-home-shirt')
            ->assertJsonPath('data.discount_percent', 25)
            ->assertJsonPath('data.in_stock', true)
            ->assertJsonStructure(['data' => ['id', 'images']]);

        $this->assertDatabaseHas('products', [
            'slug' => 'nigeria-1994-home-shirt',
            'price' => 4500,
            'stock' => 10,
            'category_id' => $category->id,
        ]);
    }

    public function test_store_requires_required_fields(): void
    {
        $this->postJson('/api/products', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug', 'team', 'year', 'description', 'price', 'stock']);
    }

    public function test_store_requires_unique_slug(): void
    {
        Product::factory()->create(['slug' => 'nigeria-1994-home-shirt']);

        $this->postJson('/api/products', $this->validProductPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'The slug has already been taken.');

        $this->assertDatabaseMissing('products', ['name' => 'Nigeria 1994 Home Shirt']);
    }

    public function test_store_rejects_malformed_slug(): void
    {
        $this->postJson('/api/products', $this->validProductPayload([
            'slug' => 'Nigeria Shirt!',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'The slug must contain only lowercase letters, numbers and dashes.');
    }

    public function test_store_rejects_invalid_price(): void
    {
        $this->postJson('/api/products', $this->validProductPayload(['price' => 'free']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);

        $this->postJson('/api/products', $this->validProductPayload(['price' => -100]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    public function test_store_rejects_negative_stock(): void
    {
        $this->postJson('/api/products', $this->validProductPayload(['stock' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stock']);
    }

    public function test_store_rejects_unknown_category_id(): void
    {
        $this->postJson('/api/products', $this->validProductPayload(['category_id' => 999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_store_with_images_creates_image_rows_and_stores_files(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/products', $this->validProductPayload([
            'images' => [$this->imageUpload('front.png'), $this->imageUpload('back.png')],
        ]));

        $response->assertStatus(201)->assertJsonCount(2, 'data.images');

        $images = Product::query()
            ->where('slug', 'nigeria-1994-home-shirt')
            ->firstOrFail()
            ->images()
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $images);
        $this->assertSame(0, $images[0]->sort_order);
        $this->assertSame(1, $images[1]->sort_order);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);

        Storage::disk('public')->assertExists($images[0]->path);
        Storage::disk('public')->assertExists($images[1]->path);
    }

    public function test_store_rejects_non_image_upload(): void
    {
        $response = $this->post('/api/products', $this->validProductPayload([
            'images' => [UploadedFile::fake()->createWithContent('notes.txt', 'just some text')],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['images.0']);
        $this->assertDatabaseMissing('products', ['slug' => 'nigeria-1994-home-shirt']);
    }

    public function test_update_changes_fields_by_id(): void
    {
        $product = Product::factory()->create([
            'name' => 'Old Name',
            'slug' => 'old-name',
            'price' => 3000,
            'stock' => 1,
        ]);

        $this->patchJson('/api/products/'.$product->id, [
            'name' => 'New Name',
            'price' => 4500,
            'stock' => 12,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.price', 4500)
            ->assertJsonPath('data.stock', 12);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'New Name', 'price' => 4500]);
    }

    public function test_update_resolves_product_by_slug(): void
    {
        $product = Product::factory()->create(['slug' => 'by-slug-update', 'name' => 'Before']);

        $this->patchJson('/api/products/by-slug-update', ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'After');
    }

    public function test_update_validates_slug_uniqueness_ignoring_self(): void
    {
        Product::factory()->create(['slug' => 'shirt-a']);
        Product::factory()->create(['slug' => 'shirt-b']);

        $this->patchJson('/api/products/shirt-b', ['slug' => 'shirt-a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);

        $this->patchJson('/api/products/shirt-b', ['slug' => 'shirt-b'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'shirt-b');
    }

    public function test_update_rejects_invalid_price(): void
    {
        $product = Product::factory()->create(['price' => 3000]);

        $this->patchJson('/api/products/'.$product->id, ['price' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'price' => 3000]);
    }

    public function test_update_appends_uploaded_images(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create();
        $existingPath = $this->imageUpload('existing.png')->store('products', 'public');

        $product->images()->create([
            'path' => $existingPath,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        $this->patch('/api/products/'.$product->id, [
            'images' => [$this->imageUpload('added.png')],
        ])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.images');

        $images = $product->images()->orderBy('sort_order')->get();

        $this->assertCount(2, $images);
        $this->assertSame($existingPath, $images[0]->path);
        $this->assertSame(1, $images[1]->sort_order);
        $this->assertTrue($images[0]->is_primary);
        $this->assertFalse($images[1]->is_primary);

        Storage::disk('public')->assertExists($existingPath);
        Storage::disk('public')->assertExists($images[1]->path);
    }

    public function test_update_returns_404_for_unknown_key(): void
    {
        $this->patchJson('/api/products/999', ['name' => 'Nope'])->assertNotFound();
        $this->patchJson('/api/products/no-such-slug', ['name' => 'Nope'])->assertNotFound();
    }

    public function test_destroy_removes_product_rows_and_stored_image_files(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create();
        $path = $this->imageUpload()->store('products', 'public');

        $product->images()->create([
            'path' => $path,
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        Storage::disk('public')->assertExists($path);

        $this->deleteJson('/api/products/'.$product->id)->assertStatus(204);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseCount('product_images', 0);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_destroy_resolves_product_by_slug(): void
    {
        $product = Product::factory()->create(['slug' => 'delete-me']);

        $this->deleteJson('/api/products/delete-me')->assertStatus(204);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_destroy_returns_404_for_unknown_key(): void
    {
        $this->deleteJson('/api/products/999')->assertNotFound();
        $this->deleteJson('/api/products/no-such-slug')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nigeria 1994 Home Shirt',
            'slug' => 'nigeria-1994-home-shirt',
            'team' => 'Nigeria',
            'year' => 1994,
            'description' => 'Classic home shirt with gold trim.',
            'price' => 4500,
            'old_price' => 6000,
            'stock' => 10,
        ], $overrides);
    }

    private function imageUpload(string $name = 'shirt.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(self::PNG, true));
    }
}
