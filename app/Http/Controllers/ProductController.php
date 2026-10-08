<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    /**
     * List products with filters, sorting and pagination.
     */
    #[OA\Get(
        path: '/products',
        summary: 'List active products',
        description: 'Paginated product list with filters, searching, sorting and computed fields (discount_percent, in_stock).',
        tags: ['products'],
        parameters: [
            new OA\Parameter(name: 'team', in: 'query', description: 'Exact team name.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'category_id', in: 'query', description: 'Exact category id.', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'search', in: 'query', description: 'Search in name and team.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['newest', 'price_asc', 'price_desc', 'year'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of products.', content: new OA\JsonContent(ref: '#/components/schemas/ProductCollection')),
            new OA\Response(response: 422, description: 'Invalid filter value.'),
        ]
    )]
    public function index(Request $request)
    {
        $filters = $request->validate([
            'team' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')],
            'search' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['newest', 'price_asc', 'price_desc', 'year'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()
            ->where('is_active', true)
            ->with(['images' => fn ($query) => $query->orderBy('sort_order')]);

        if (isset($filters['team'])) {
            $query->where('team', $filters['team']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('team', 'like', "%{$search}%");
            });
        }

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price')->orderBy('id'),
            'year' => $query->orderByDesc('year')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        return ProductResource::collection(
            $query->paginate($filters['per_page'] ?? 12)->withQueryString()
        );
    }

    /**
     * Create a product with optional images (admin).
     */
    #[OA\Post(
        path: '/products',
        summary: 'Create a product',
        description: 'Create a product and, when images are sent, store them on the public disk and create their rows in the same transaction (admin).',
        tags: ['products'],
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\JsonContent(ref: '#/components/schemas/ProductRequest'),
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(ref: '#/components/schemas/ProductRequest')
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

        try {
            $product = DB::transaction(function () use ($data, $files, &$storedPaths) {
                $product = Product::create($data);

                $this->storeImages($product, $files, $storedPaths);

                return $product;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);

            throw $exception;
        }

        return (new ProductResource($product->load(['images' => fn ($query) => $query->orderBy('sort_order')])))
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
        $product = Product::findByKey($key);

        abort_unless($product->is_active, 404);

        return new ProductResource($product->load(['images' => fn ($query) => $query->orderBy('sort_order')]));
    }

    /**
     * Update a product's fields and append any uploaded images (admin).
     */
    #[OA\Patch(
        path: '/products/{key}',
        summary: 'Update a product',
        description: 'Update product fields by id or slug and append uploaded images (admin).',
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
        $product = Product::findByKey($key);

        $data = $request->validated();
        $files = $data['images'] ?? [];
        unset($data['images']);

        $storedPaths = [];

        try {
            DB::transaction(function () use ($product, $data, $files, &$storedPaths) {
                $product->update($data);

                $this->storeImages($product, $files, $storedPaths);
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);

            throw $exception;
        }

        return new ProductResource($product->load(['images' => fn ($query) => $query->orderBy('sort_order')]));
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
        $product = Product::findByKey($key);
        $paths = $product->images()->pluck('path')->all();

        $product->delete();

        Storage::disk('public')->delete($paths);

        return response()->json(null, 204);
    }

    /**
     * Store the uploaded images on the public disk and create their rows.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $storedPaths
     */
    private function storeImages(Product $product, array $files, array &$storedPaths): void
    {
        if ($files === []) {
            return;
        }

        $lastSortOrder = $product->images()->max('sort_order');
        $nextSortOrder = $lastSortOrder === null ? 0 : ((int) $lastSortOrder) + 1;
        $hasPrimary = $product->images()->where('is_primary', true)->exists();

        foreach (array_values($files) as $index => $file) {
            $path = $file->store('products', 'public');

            if ($path === false) {
                throw new RuntimeException('Unable to store the product image.');
            }

            $storedPaths[] = $path;

            $product->images()->create([
                'path' => $path,
                'sort_order' => $nextSortOrder++,
                'is_primary' => $index === 0 && ! $hasPrimary,
            ]);
        }
    }
}
