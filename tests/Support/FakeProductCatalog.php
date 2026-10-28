<?php

namespace Tests\Support;

use App\Contracts\ProductCatalog;

class FakeProductCatalog implements ProductCatalog
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $products = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $images = [];

    private int $nextProductId = 1;

    private int $nextImageId = 1;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function createProduct(array $attributes = []): array
    {
        return $this->create($attributes);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function products(): array
    {
        return array_values($this->products);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        $products = array_filter($this->products, fn (array $product): bool => $product['is_active']);
        $products = array_reverse($products);

        return array_values(array_map(function (array $product): array {
            $product['images'] = $this->imagesForProduct($product['id']);

            return $product;
        }, $products));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        if (ctype_digit($key)) {
            $product = $this->findById($key);
        } else {
            $product = $this->findBySlug($key);
        }

        if ($product === null) {
            return null;
        }

        $product['images'] = $this->imagesForProduct($product['id']);

        return $product;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string|int $id): ?array
    {
        return $this->products[(int) $id] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBySlug(string $slug): ?array
    {
        foreach ($this->products as $product) {
            if ($product['slug'] === $slug) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        $products = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (isset($this->products[$id])) {
                $products[$id] = $this->products[$id];
            }
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $id = $this->nextProductId++;
        $now = now()->toDateTimeString();

        $product = [
            'id' => $id,
            'name' => (string) ($data['name'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'team' => (string) ($data['team'] ?? ''),
            'year' => (int) ($data['year'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
            'price' => (int) ($data['price'] ?? 0),
            'old_price' => isset($data['old_price']) ? (int) $data['old_price'] : null,
            'stock' => (int) ($data['stock'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'size' => $data['size'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
            'images' => [],
        ];

        $this->products[$id] = $product;

        return $product;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $productId = (int) $id;

        if (! isset($this->products[$productId])) {
            return [];
        }

        $allowed = [
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

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $this->products[$productId][$key] = $data[$key];
            }
        }

        $this->products[$productId]['updated_at'] = now()->toDateTimeString();

        return $this->products[$productId];
    }

    public function delete(string $id): void
    {
        unset($this->products[(int) $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function images(string $productId): array
    {
        return $this->imagesForProduct((int) $productId);
    }

    /**
     * @return array<string, mixed>
     */
    public function addImage(string $productId, string $path, int $sortOrder, bool $isPrimary): array
    {
        $id = $this->nextImageId++;

        $image = [
            'id' => $id,
            'product_id' => (int) $productId,
            'path' => $path,
            'sort_order' => $sortOrder,
            'is_primary' => $isPrimary,
        ];

        $this->images[$id] = $image;

        return $image;
    }

    public function deleteImage(string $imageId): void
    {
        unset($this->images[(int) $imageId]);
    }

    public function deleteImagesForProduct(string $productId): void
    {
        foreach ($this->imagesForProduct((int) $productId) as $image) {
            $this->deleteImage((string) $image['id']);
        }
    }

    public function decrementStock(string $productId, int $quantity): bool
    {
        $product = $this->findById($productId);

        if ($product === null || $product['stock'] < $quantity) {
            return false;
        }

        $this->products[(int) $productId]['stock'] -= $quantity;

        return true;
    }

    public function incrementStock(string $productId, int $quantity): void
    {
        if (isset($this->products[(int) $productId])) {
            $this->products[(int) $productId]['stock'] += $quantity;
        }
    }

    public function existsForCategory(int $categoryId): bool
    {
        foreach ($this->products as $product) {
            if ($product['category_id'] === $categoryId) {
                return true;
            }
        }

        return false;
    }

    public function slugExists(string $slug, int|string|null $ignoreId = null): bool
    {
        foreach ($this->products as $product) {
            if ($product['slug'] === $slug && ($ignoreId === null || $product['id'] !== (int) $ignoreId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function imagesForProduct(int $productId): array
    {
        $images = array_filter(
            $this->images,
            fn (array $image): bool => $image['product_id'] === $productId
        );

        usort($images, fn (array $left, array $right): int => [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']]);

        return array_values($images);
    }
}
