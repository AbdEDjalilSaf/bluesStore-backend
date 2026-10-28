<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://localhost',
            'filesystems.product_images_driver' => 'local',
        ]);
    }

    public function test_index_returns_all_active_shirts_without_pagination(): void
    {
        $this->makeProduct(['name' => 'First']);
        $this->makeProduct(['name' => 'Second']);
        $this->makeProduct(['name' => 'Third']);
        $this->makeProduct(['name' => 'Hidden Shirt', 'slug' => 'hidden-shirt', 'is_active' => false]);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');

        $this->assertNotContains('hidden-shirt', array_column($response->json('data'), 'slug'));
    }

    public function test_index_ignores_filter_search_sort_and_pagination_params(): void
    {
        $this->makeProduct([
            'name' => 'Algeria Home',
            'slug' => 'algeria-home',
            'team' => 'Algeria',
            'price' => 5000,
        ]);
        $this->makeProduct([
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
        $this->makeProduct([
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
        $this->makeProduct([
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

    public function test_index_builds_local_image_urls_from_the_current_request_host(): void
    {
        config(['filesystems.disks.public.url' => 'https://old-api.example.test/storage']);

        $product = $this->makeProduct();
        $this->catalog->addImage((string) $product['id'], 'products/front.png', 0, true);

        $this->getJson('http://localhost/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.images.0.path', 'products/front.png')
            ->assertJsonPath('data.0.images.0.url', 'http://localhost/storage/products/front.png');
    }

    public function test_index_builds_appwrite_file_view_urls(): void
    {
        $this->configureAppwrite();

        $product = $this->makeProduct();
        $this->catalog->addImage((string) $product['id'], 'appwrite:file-123', 0, true);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.images.0.path', 'appwrite:file-123')
            ->assertJsonPath(
                'data.0.images.0.url',
                'https://appwrite.example.test/v1/storage/buckets/product-images/files/file-123/view?project=project-123',
            );
    }

    public function test_store_uploads_images_to_appwrite_and_persists_paths(): void
    {
        $this->configureAppwrite();

        Http::fake(function (ClientRequest $request) {
            static $fileNumber = 0;
            $fileNumber++;

            return Http::response(['$id' => 'file-'.$fileNumber], 201);
        });

        $this->post('/api/products', $this->validProductPayload([
            'images' => [$this->imageUpload('front.png'), $this->imageUpload('back.png')],
        ]))
            ->assertStatus(201)
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.path', 'appwrite:file-1')
            ->assertJsonPath('data.images.1.path', 'appwrite:file-2');

        Http::assertSentCount(2);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://appwrite.example.test/v1/storage/buckets/product-images/files'
                && $request->hasHeader('X-Appwrite-Project', 'project-123')
                && $request->hasHeader('X-Appwrite-Key', 'api-key');
        });
    }

    public function test_store_rolls_back_when_appwrite_upload_fails(): void
    {
        $this->configureAppwrite();

        Http::fake([
            'appwrite.example.test/*' => Http::response(['message' => 'Storage unavailable.'], 500),
        ]);

        $this->post('/api/products', $this->validProductPayload([
            'images' => [$this->imageUpload('front.png')],
        ]))->assertStatus(500);

        $this->assertSame([], $this->catalog->products());
        $this->assertSame([], $this->catalog->images('1'));
    }

    public function test_destroy_deletes_appwrite_images(): void
    {
        $this->configureAppwrite();

        Http::fake([
            'appwrite.example.test/*' => Http::response(null, 204),
        ]);

        $product = $this->makeProduct();
        $this->catalog->addImage((string) $product['id'], 'appwrite:file-123', 0, true);

        $this->deleteJson('/api/products/'.$product['id'])->assertStatus(204);

        $this->assertNull($this->catalog->find((string) $product['id']));
        $this->assertSame([], $this->catalog->images((string) $product['id']));
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://appwrite.example.test/v1/storage/buckets/product-images/files/file-123';
        });
    }

    public function test_show_returns_404_for_unknown_or_inactive_slug(): void
    {
        $this->getJson('/api/products/does-not-exist')->assertNotFound();

        $this->makeProduct(['name' => 'Hidden', 'slug' => 'hidden-product', 'is_active' => false]);

        $this->getJson('/api/products/hidden-product')->assertNotFound();
    }

    public function test_show_returns_product_by_id(): void
    {
        $product = $this->makeProduct(['name' => 'By Id', 'slug' => 'by-id']);

        $this->getJson('/api/products/'.$product['id'])
            ->assertOk()
            ->assertJsonPath('data.id', $product['id'])
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

        $product = $this->catalog->find('nigeria-1994-home-shirt');

        $this->assertNotNull($product);
        $this->assertSame(4500, $product['price']);
        $this->assertSame(10, $product['stock']);
        $this->assertSame($category->id, $product['category_id']);
    }

    public function test_store_persists_size(): void
    {
        $this->postJson('/api/products', $this->validProductPayload([
            'size' => 'S,M,L,XL',
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.size', 'S,M,L,XL');

        $this->assertSame('S,M,L,XL', $this->catalog->find('nigeria-1994-home-shirt')['size']);
    }

    public function test_store_requires_required_fields(): void
    {
        $this->postJson('/api/products', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug', 'team', 'year', 'description', 'price', 'stock']);
    }

    public function test_store_requires_unique_slug(): void
    {
        $this->makeProduct(['slug' => 'nigeria-1994-home-shirt']);

        $this->postJson('/api/products', $this->validProductPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.slug.0', 'The slug has already been taken.');

        $this->assertCount(1, $this->catalog->products());
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

        $product = $this->catalog->find('nigeria-1994-home-shirt');
        $images = $this->catalog->images((string) $product['id']);

        $this->assertCount(2, $images);
        $this->assertSame(0, $images[0]['sort_order']);
        $this->assertSame(1, $images[1]['sort_order']);
        $this->assertTrue($images[0]['is_primary']);
        $this->assertFalse($images[1]['is_primary']);

        Storage::disk('public')->assertExists($images[0]['path']);
        Storage::disk('public')->assertExists($images[1]['path']);
    }

    public function test_store_rejects_non_image_upload(): void
    {
        $response = $this->post('/api/products', $this->validProductPayload([
            'images' => [UploadedFile::fake()->createWithContent('notes.txt', 'just some text')],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['images.0']);
        $this->assertSame([], $this->catalog->products());
    }

    public function test_update_changes_size(): void
    {
        $product = $this->makeProduct(['size' => 'M']);

        $this->patchJson('/api/products/'.$product['id'], ['size' => 'L,XL'])
            ->assertOk()
            ->assertJsonPath('data.size', 'L,XL');

        $this->assertSame('L,XL', $this->catalog->find($product['id'])['size']);
    }

    public function test_update_changes_fields_by_id(): void
    {
        $product = $this->makeProduct([
            'name' => 'Old Name',
            'slug' => 'old-name',
            'price' => 3000,
            'stock' => 1,
        ]);

        $this->patchJson('/api/products/'.$product['id'], [
            'name' => 'New Name',
            'price' => 4500,
            'stock' => 12,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.price', 4500)
            ->assertJsonPath('data.stock', 12);

        $updated = $this->catalog->find($product['id']);
        $this->assertSame('New Name', $updated['name']);
        $this->assertSame(4500, $updated['price']);
    }

    public function test_update_resolves_product_by_slug(): void
    {
        $product = $this->makeProduct(['slug' => 'by-slug-update', 'name' => 'Before']);

        $this->patchJson('/api/products/by-slug-update', ['name' => 'After'])
            ->assertOk()
            ->assertJsonPath('data.id', $product['id'])
            ->assertJsonPath('data.name', 'After');
    }

    public function test_update_validates_slug_uniqueness_ignoring_self(): void
    {
        $this->makeProduct(['slug' => 'shirt-a']);
        $this->makeProduct(['slug' => 'shirt-b']);

        $this->patchJson('/api/products/shirt-b', ['slug' => 'shirt-a'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);

        $this->patchJson('/api/products/shirt-b', ['slug' => 'shirt-b'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'shirt-b');
    }

    public function test_update_rejects_invalid_price(): void
    {
        $product = $this->makeProduct(['price' => 3000]);

        $this->patchJson('/api/products/'.$product['id'], ['price' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);

        $this->assertSame(3000, $this->catalog->find($product['id'])['price']);
    }

    public function test_update_appends_uploaded_images(): void
    {
        Storage::fake('public');

        $product = $this->makeProduct();
        $existingPath = $this->imageUpload('existing.png')->store('products', 'public');

        $this->catalog->addImage((string) $product['id'], $existingPath, 0, true);

        $this->post('/api/products/'.$product['id'], [
            '_method' => 'PATCH',
            'images' => [$this->imageUpload('added.png')],
        ])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.images');

        $images = $this->catalog->images((string) $product['id']);

        $this->assertCount(2, $images);
        $this->assertSame($existingPath, $images[0]['path']);
        $this->assertSame(1, $images[1]['sort_order']);
        $this->assertTrue($images[0]['is_primary']);
        $this->assertFalse($images[1]['is_primary']);

        Storage::disk('public')->assertExists($existingPath);
        Storage::disk('public')->assertExists($images[1]['path']);
    }

    public function test_update_can_replace_the_existing_image_gallery(): void
    {
        Storage::fake('public');

        $product = $this->makeProduct();
        $existingPath = $this->imageUpload('old.png')->store('products', 'public');
        $this->catalog->addImage((string) $product['id'], $existingPath, 0, true);

        $response = $this->post('/api/products/'.$product['id'], [
            '_method' => 'PATCH',
            'replace_images' => '1',
            'images' => [$this->imageUpload('replacement.png')],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.images.0.is_primary', true);

        $replacementPath = $response->json('data.images.0.path');

        $this->assertSame([$replacementPath], array_column($this->catalog->images((string) $product['id']), 'path'));
        Storage::disk('public')->assertMissing($existingPath);
        Storage::disk('public')->assertExists($replacementPath);
    }

    public function test_replacing_images_requires_at_least_one_new_upload(): void
    {
        $product = $this->makeProduct();

        $this->post('/api/products/'.$product['id'], [
            '_method' => 'PATCH',
            'replace_images' => '1',
            'name' => $product['name'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images']);
    }

    public function test_update_returns_404_for_unknown_key(): void
    {
        $this->patchJson('/api/products/999', ['name' => 'Nope'])->assertNotFound();
        $this->patchJson('/api/products/no-such-slug', ['name' => 'Nope'])->assertNotFound();
    }

    public function test_destroy_removes_product_rows_and_stored_image_files(): void
    {
        Storage::fake('public');

        $product = $this->makeProduct();
        $path = $this->imageUpload()->store('products', 'public');

        $this->catalog->addImage((string) $product['id'], $path, 0, true);

        Storage::disk('public')->assertExists($path);

        $this->deleteJson('/api/products/'.$product['id'])->assertStatus(204);

        $this->assertNull($this->catalog->find($product['id']));
        $this->assertSame([], $this->catalog->images((string) $product['id']));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_destroy_resolves_product_by_slug(): void
    {
        $product = $this->makeProduct(['slug' => 'delete-me']);

        $this->deleteJson('/api/products/delete-me')->assertStatus(204);

        $this->assertNull($this->catalog->find($product['id']));
    }

    public function test_destroy_returns_404_for_unknown_key(): void
    {
        $this->deleteJson('/api/products/999')->assertNotFound();
        $this->deleteJson('/api/products/no-such-slug')->assertNotFound();
    }

    private function configureAppwrite(): void
    {
        config([
            'filesystems.product_images_driver' => 'appwrite',
            'services.appwrite.endpoint' => 'https://appwrite.example.test/v1',
            'services.appwrite.project' => 'project-123',
            'services.appwrite.key' => 'api-key',
            'services.appwrite.bucket' => 'product-images',
        ]);
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
