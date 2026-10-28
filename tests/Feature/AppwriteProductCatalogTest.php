<?php

namespace Tests\Feature;

use App\Services\AppwriteProductCatalog;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppwriteProductCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.appwrite.endpoint' => 'https://appwrite.example.test/v1',
            'services.appwrite.project' => 'project-123',
            'services.appwrite.key' => 'api-key',
            'services.appwrite.database' => 'database-123',
            'services.appwrite.verify' => true,
        ]);
    }

    public function test_all_active_products_include_images_and_use_appwrite_query_syntax(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/tables/products/rows/query')) {
                return Http::response(['total' => 2, 'rows' => [
                    $this->productRow('1', 'older-shirt', '2026-01-01T00:00:00.000+00:00'),
                    $this->productRow('2', 'newer-shirt', '2026-01-02T00:00:00.000+00:00'),
                ]]);
            }

            if (str_contains($request->url(), '/tables/product_images/rows/query')) {
                $queries = implode(' ', $request->data()['queries'] ?? []);

                return Http::response([
                    'total' => str_contains($queries, '"values":["2"]') ? 1 : 0,
                    'rows' => str_contains($queries, '"values":["2"]') ? [$this->imageRow('1', '2')] : [],
                ]);
            }

            return Http::response(['message' => 'Unexpected request.'], 500);
        });

        $products = app(AppwriteProductCatalog::class)->allActive();

        $this->assertSame(['newer-shirt', 'older-shirt'], array_column($products, 'slug'));
        $this->assertSame('appwrite:file-1', $products[0]['images'][0]['path']);
        $this->assertSame([], $products[1]['images']);

        Http::assertSent(function (ClientRequest $request): bool {
            if (! str_contains($request->url(), '/tables/products/rows/query')) {
                return false;
            }

            $queries = $request->data()['queries'] ?? [];

            return in_array('{"method":"equal","attribute":"is_active","values":[true]}', $queries, true)
                && in_array('{"method":"limit","values":[100]}', $queries, true)
                && in_array('{"method":"offset","values":[0]}', $queries, true);
        });
    }

    public function test_find_resolves_slugs_ids_and_batches(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/tables/products/rows/1')) {
                return Http::response($this->productRow('1', 'by-id'));
            }

            if (str_contains($request->url(), '/tables/products/rows/query')) {
                $queries = implode(' ', $request->data()['queries'] ?? []);

                if (str_contains($queries, '"attribute":"slug"')) {
                    return Http::response(['total' => 1, 'rows' => [$this->productRow('2', 'by-slug')]]);
                }

                return Http::response(['total' => 2, 'rows' => [
                    $this->productRow('1', 'by-id'),
                    $this->productRow('2', 'by-slug'),
                ]]);
            }

            return Http::response(['total' => 0, 'rows' => []]);
        });

        $catalog = app(AppwriteProductCatalog::class);

        $this->assertSame('by-id', $catalog->find('1')['slug']);
        $this->assertSame('by-slug', $catalog->find('by-slug')['slug']);
        $this->assertSame([1, 2], array_keys($catalog->findMany([1, 2, 1])));
    }

    public function test_create_allocates_the_next_numeric_id_and_update_is_partial(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/tables/products/rows/query')) {
                return Http::response(['total' => 2, 'rows' => [
                    ['$id' => '2'],
                    ['$id' => '7'],
                ]]);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/tables/products/rows')) {
                return Http::response($this->productRow('8', 'new-shirt'), 201);
            }

            if ($request->method() === 'PATCH' && str_ends_with($request->url(), '/tables/products/rows/8')) {
                return Http::response($this->productRow('8', 'new-shirt', name: 'Updated Name'));
            }

            return Http::response(['message' => 'Unexpected request.'], 500);
        });

        $catalog = app(AppwriteProductCatalog::class);

        $product = $catalog->create([
            'name' => 'New Shirt',
            'slug' => 'new-shirt',
            'team' => 'Algeria',
            'year' => 2026,
            'description' => 'A new shirt.',
            'price' => 5000,
            'stock' => 4,
        ]);

        $this->assertSame(8, $product['id']);

        $updated = $catalog->update('8', ['name' => 'Updated Name']);

        $this->assertSame('Updated Name', $updated['name']);

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $data = $request->data()['data'] ?? [];

            return $data === ['name' => 'Updated Name'];
        });
    }

    public function test_stock_cannot_be_decremented_below_zero(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->method() === 'GET') {
                return Http::response($this->productRow('1', 'stock-shirt', stock: 2));
            }

            if ($request->method() === 'PATCH') {
                return Http::response($this->productRow('1', 'stock-shirt', stock: 1));
            }

            return Http::response(['total' => 0, 'rows' => []]);
        });

        $catalog = app(AppwriteProductCatalog::class);

        $this->assertFalse($catalog->decrementStock('1', 3));
        $this->assertTrue($catalog->decrementStock('1', 1));

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'PATCH'
                && ($request->data()['data'] ?? []) === ['stock' => 1];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(
        string $id,
        string $slug,
        string $createdAt = '2026-01-01T00:00:00.000+00:00',
        string $name = 'Test Shirt',
        int $stock = 5,
    ): array {
        return [
            '$id' => $id,
            '$createdAt' => $createdAt,
            '$updatedAt' => $createdAt,
            'name' => $name,
            'slug' => $slug,
            'team' => 'Algeria',
            'year' => 2026,
            'description' => 'A test shirt.',
            'price' => 3000,
            'old_price' => null,
            'stock' => $stock,
            'is_active' => true,
            'category_id' => null,
            'size' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageRow(string $id, string $productId): array
    {
        return [
            '$id' => $id,
            'product_id' => $productId,
            'path' => 'appwrite:file-1',
            'sort_order' => 0,
            'is_primary' => true,
        ];
    }
}
