<?php

namespace App\Services;

use App\Contracts\ProductCatalog;
use App\Support\AppwriteTables;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class AppwriteProductCatalog implements ProductCatalog
{
    public function __construct(
        private readonly AppwriteTables $tables
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        $rows = $this->tables->listAllRows(AppwriteTables::PRODUCTS, [
            AppwriteTables::equal('is_active', true),
        ]);

        $products = array_map(fn (array $row): array => $this->productFromRow($row), $rows);

        usort($products, function (array $left, array $right): int {
            return [$right['created_at'], $right['id']] <=> [$left['created_at'], $left['id']];
        });

        return array_map(function (array $product): array {
            $product['images'] = $this->images((string) $product['id']);

            return $product;
        }, $products);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        if (ctype_digit($key)) {
            $product = $this->findById($key);
        } else {
            $rows = $this->tables->listRows(AppwriteTables::PRODUCTS, [
                AppwriteTables::equal('slug', $key),
                AppwriteTables::limit(1),
            ]);

            $product = $rows === [] ? null : $this->productFromRow($rows[0]);
        }

        if ($product === null) {
            return null;
        }

        $product['images'] = $this->images((string) $product['id']);

        return $product;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string|int $id): ?array
    {
        $row = $this->tables->getRow(AppwriteTables::PRODUCTS, (string) $id);

        return $row === null ? null : $this->productFromRow($row);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));

        if ($ids === []) {
            return [];
        }

        $rows = $this->tables->listAllRows(AppwriteTables::PRODUCTS, [
            AppwriteTables::equal('$id', $ids),
        ]);

        $products = [];

        foreach ($rows as $row) {
            $product = $this->productFromRow($row);
            $products[$product['id']] = $product;
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        for ($attempts = 0; $attempts < 3; $attempts++) {
            $rowId = (string) $this->tables->nextNumericId(AppwriteTables::PRODUCTS);

            try {
                $row = $this->tables->createRow(
                    AppwriteTables::PRODUCTS,
                    $rowId,
                    $this->productData($data)
                );

                return $this->productFromRow($row);
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 409 || $attempts === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to allocate a product ID.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $row = $this->tables->updateRow(
            AppwriteTables::PRODUCTS,
            $id,
            $this->productData($data, partial: true)
        );

        return $this->productFromRow($row);
    }

    public function delete(string $id): void
    {
        $this->tables->deleteRow(AppwriteTables::PRODUCTS, $id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function images(string $productId): array
    {
        $rows = $this->tables->listAllRows(AppwriteTables::PRODUCT_IMAGES, [
            AppwriteTables::equal('product_id', $productId),
        ]);

        $images = array_map(fn (array $row): array => $this->imageFromRow($row), $rows);

        usort($images, function (array $left, array $right): int {
            return [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']];
        });

        return $images;
    }

    /**
     * @return array<string, mixed>
     */
    public function addImage(string $productId, string $path, int $sortOrder, bool $isPrimary): array
    {
        $rowId = (string) $this->tables->nextNumericId(AppwriteTables::PRODUCT_IMAGES);

        $row = $this->tables->createRow(AppwriteTables::PRODUCT_IMAGES, $rowId, [
            'product_id' => $productId,
            'path' => $path,
            'sort_order' => $sortOrder,
            'is_primary' => $isPrimary,
        ]);

        return $this->imageFromRow($row);
    }

    public function deleteImage(string $imageId): void
    {
        $this->tables->deleteRow(AppwriteTables::PRODUCT_IMAGES, $imageId);
    }

    public function deleteImagesForProduct(string $productId): void
    {
        foreach ($this->images($productId) as $image) {
            $this->deleteImage((string) $image['id']);
        }
    }

    public function decrementStock(string $productId, int $quantity): bool
    {
        $product = $this->findById($productId);

        if ($product === null || $product['stock'] < $quantity) {
            return false;
        }

        $this->update($productId, ['stock' => $product['stock'] - $quantity]);

        return true;
    }

    public function incrementStock(string $productId, int $quantity): void
    {
        $product = $this->findById($productId);

        if ($product === null) {
            return;
        }

        $this->update($productId, ['stock' => $product['stock'] + $quantity]);
    }

    public function existsForCategory(int $categoryId): bool
    {
        $rows = $this->tables->listRows(AppwriteTables::PRODUCTS, [
            AppwriteTables::equal('category_id', $categoryId),
            AppwriteTables::limit(1),
        ]);

        return $rows !== [];
    }

    public function slugExists(string $slug, int|string|null $ignoreId = null): bool
    {
        $rows = $this->tables->listRows(AppwriteTables::PRODUCTS, [
            AppwriteTables::equal('slug', $slug),
            AppwriteTables::limit(2),
        ]);

        foreach ($rows as $row) {
            if ($ignoreId === null || (string) $row['$id'] !== (string) $ignoreId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function productData(array $data, bool $partial = false): array
    {
        $columns = [
            'name',
            'slug',
            'team',
            'year',
            'description',
            'price',
            'old_price',
            'stock',
            'is_active',
            'category_id',
            'size',
        ];

        $payload = [];

        foreach ($columns as $column) {
            if ($partial && ! array_key_exists($column, $data)) {
                continue;
            }

            $payload[$column] = match ($column) {
                'year', 'price', 'stock' => (int) ($data[$column] ?? 0),
                'old_price' => isset($data['old_price']) ? (int) $data['old_price'] : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
                'size' => $data['size'] ?? null,
                default => (string) ($data[$column] ?? ''),
            };
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function productFromRow(array $row): array
    {
        return [
            'id' => (int) $row['$id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'team' => (string) $row['team'],
            'year' => (int) $row['year'],
            'description' => (string) $row['description'],
            'price' => (int) $row['price'],
            'old_price' => $row['old_price'] === null ? null : (int) $row['old_price'],
            'stock' => (int) $row['stock'],
            'is_active' => (bool) $row['is_active'],
            'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'size' => $row['size'] === null ? null : (string) $row['size'],
            'created_at' => (string) $row['$createdAt'],
            'updated_at' => (string) $row['$updatedAt'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function imageFromRow(array $row): array
    {
        return [
            'id' => (int) $row['$id'],
            'product_id' => (int) $row['product_id'],
            'path' => (string) $row['path'],
            'sort_order' => (int) $row['sort_order'],
            'is_primary' => (bool) $row['is_primary'],
        ];
    }
}
