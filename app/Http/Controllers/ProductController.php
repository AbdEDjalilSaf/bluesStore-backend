<?php

namespace App\Http\Controllers;

use App\Contracts\ProductCatalog;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Support\ProductImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use OpenApi\Attributes as OA;
use Throwable;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductCatalog $catalog
    ) {}

    /**
     * List all active shirts without pagination or filters.
     */
    #[OA\Get(
        path: '/products',
        summary: 'List all active shirts',
        description: 'Returns all active shirts without pagination, filters, or sorting.',
        tags: ['products'],
        responses: [
            new OA\Response(response: 200, description: 'List of all active shirts.', content: new OA\JsonContent(ref: '#/components/schemas/ProductCollection')),
        ]
    )]
    public function index(Request $request)
    {
        return ProductResource::collection($this->catalog->allActive());
    }

    /**
     * Create a product with optional images (admin).
     */
    #[OA\Post(
        path: '/products',
        summary: 'Create a product',
        description: 'Create a product and, when images are sent, store them on the configured product image provider and create their rows in Appwrite TablesDB (admin).',
        tags: ['products'],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\JsonContent(ref: '#/components/schemas/ProductRequest'),
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(
                        required: ['name', 'slug', 'team', 'year', 'description', 'price', 'stock'],
                        properties: [
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'slug', type: 'string'),
                            new OA\Property(property: 'team', type: 'string'),
                            new OA\Property(property: 'year', type: 'integer'),
                            new OA\Property(property: 'description', type: 'string'),
                            new OA\Property(property: 'price', type: 'integer'),
                            new OA\Property(property: 'old_price', type: 'integer'),
                            new OA\Property(property: 'stock', type: 'integer'),
                            new OA\Property(property: 'size', type: 'string'),
                            new OA\Property(property: 'category_id', type: 'integer'),
                            new OA\Property(property: 'is_active', type: 'boolean'),
                            new OA\Property(
                                property: 'images',
                                type: 'array',
                                items: new OA\Items(type: 'string', format: 'binary')
                            ),
                        ]
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(response: 201, description: 'Product created.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();
        $files = $data['images'] ?? [];
        unset($data['images']);

        $storedPaths = [];
        $product = null;
        $createdImageIds = [];

        try {
            $product = $this->catalog->create($data);
            $this->storeFiles($files, $storedPaths);

            foreach ($storedPaths as $index => $path) {
                $image = $this->catalog->addImage((string) $product['id'], $path, $index, $index === 0);
                $createdImageIds[] = (string) $image['id'];
            }
        } catch (Throwable $exception) {
            foreach ($createdImageIds as $imageId) {
                $this->deleteImageQuietly($imageId);
            }

            if ($product !== null) {
                try {
                    $this->catalog->deleteImagesForProduct((string) $product['id']);
                    $this->catalog->delete((string) $product['id']);
                } catch (Throwable) {
                    // The original failure is more useful than a secondary cleanup failure.
                }
            }

            ProductImageStorage::delete($storedPaths);

            throw $exception;
        }

        return (new ProductResource($this->catalog->find((string) $product['id'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a single product by its id or slug.
     */
    #[OA\Get(
        path: '/products/{key}',
        summary: 'Show a product',
        description: 'Returns a single active product, resolved by id or slug.',
        tags: ['products'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'path', required: true, description: 'Product id or slug.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The product.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 404, description: 'Product not found or inactive.'),
        ]
    )]
    public function show(string $key)
    {
        $product = $this->catalog->find($key);

        abort_if($product === null || ! $product['is_active'], 404);

        return new ProductResource($product);
    }

    /**
     * Update a product's fields and append or replace uploaded images (admin).
     */
    #[OA\Patch(
        path: '/products/{key}',
        summary: 'Update a product',
        description: 'Update product fields by id or slug. Uploaded images are appended by default; set replace_images to replace the gallery.',
        tags: ['products'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'path', required: true, description: 'Product id or slug.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\JsonContent(ref: '#/components/schemas/ProductUpdateRequest'),
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(ref: '#/components/schemas/ProductUpdateRequest')
                ),
            ]
        ),
        responses: [
            new OA\Response(response: 200, description: 'Product updated.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 404, description: 'Product not found.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function update(UpdateProductRequest $request, string $key)
    {
        $product = $this->catalog->find($key);

        abort_if($product === null, 404);

        $data = $request->validated();
        $files = $data['images'] ?? [];
        $replaceImages = filter_var($data['replace_images'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($data['images'], $data['replace_images']);

        $storedPaths = [];
        $createdImageIds = [];
        $pathsToDelete = [];

        try {
            $this->storeFiles($files, $storedPaths);

            $existingImages = $this->catalog->images((string) $product['id']);
            $lastSortOrder = $existingImages === []
                ? null
                : max(array_column($existingImages, 'sort_order'));
            $nextSortOrder = $replaceImages || $lastSortOrder === null
                ? 0
                : ((int) $lastSortOrder) + 1;
            $hasPrimary = ! $replaceImages && in_array(true, array_column($existingImages, 'is_primary'), true);

            $this->catalog->update((string) $product['id'], $data);

            foreach ($storedPaths as $index => $path) {
                $image = $this->catalog->addImage(
                    (string) $product['id'],
                    $path,
                    $nextSortOrder + $index,
                    $index === 0 && ! $hasPrimary
                );
                $createdImageIds[] = (string) $image['id'];
            }

            if ($replaceImages) {
                foreach ($existingImages as $image) {
                    $this->catalog->deleteImage((string) $image['id']);
                    $pathsToDelete[] = $image['path'];
                }
            }
        } catch (Throwable $exception) {
            foreach ($createdImageIds as $imageId) {
                $this->deleteImageQuietly($imageId);
            }

            ProductImageStorage::delete($storedPaths);

            throw $exception;
        }

        ProductImageStorage::delete($pathsToDelete);

        return new ProductResource($this->catalog->find((string) $product['id']));
    }

    /**
     * Delete a product permanently, including its image files (admin).
     */
    #[OA\Delete(
        path: '/products/{key}',
        summary: 'Delete a product',
        description: 'Delete a product by id or slug. Removes its image rows and stored image files (admin).',
        tags: ['products'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'path', required: true, description: 'Product id or slug.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Product deleted.'),
            new OA\Response(response: 404, description: 'Product not found.'),
        ]
    )]
    public function destroy(string $key): JsonResponse
    {
        $product = $this->catalog->find($key);

        abort_if($product === null, 404);

        $paths = array_column($product['images'], 'path');

        $this->catalog->delete((string) $product['id']);
        $this->catalog->deleteImagesForProduct((string) $product['id']);

        ProductImageStorage::delete($paths);

        return response()->json(null, 204);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $storedPaths
     */
    private function storeFiles(array $files, array &$storedPaths): void
    {
        foreach (array_values($files) as $file) {
            $storedPaths[] = ProductImageStorage::store($file);
        }
    }

    private function deleteImageQuietly(string $imageId): void
    {
        try {
            $this->catalog->deleteImage($imageId);
        } catch (Throwable) {
            // Preserve the original failure.
        }
    }
}
