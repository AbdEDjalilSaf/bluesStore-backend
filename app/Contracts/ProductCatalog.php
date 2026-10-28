<?php

namespace App\Contracts;

interface ProductCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string|int $id): ?array;

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function findMany(array $ids): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array;

    public function delete(string $id): void;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function images(string $productId): array;

    /**
     * @return array<string, mixed>
     */
    public function addImage(string $productId, string $path, int $sortOrder, bool $isPrimary): array;

    public function deleteImage(string $imageId): void;

    public function deleteImagesForProduct(string $productId): void;

    public function decrementStock(string $productId, int $quantity): bool;

    public function incrementStock(string $productId, int $quantity): void;

    public function existsForCategory(int $categoryId): bool;

    public function slugExists(string $slug, int|string|null $ignoreId = null): bool;
}
