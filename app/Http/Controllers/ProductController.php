<?php

namespace App\Http\Controllers;

use App\Enums\Condition;
use App\Enums\Rarity;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

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
            new OA\Parameter(name: 'rarity', in: 'query', schema: new OA\Schema(type: 'string', enum: ['very_rare', 'rare', 'iconic', 'limited'])),
            new OA\Parameter(name: 'condition', in: 'query', schema: new OA\Schema(type: 'string', enum: ['mint_with_tags', 'excellent', 'very_good'])),
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
            'rarity' => ['sometimes', Rule::enum(Rarity::class)],
            'condition' => ['sometimes', Rule::enum(Condition::class)],
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

        if (isset($filters['rarity'])) {
            $query->where('rarity', $filters['rarity']);
        }

        if (isset($filters['condition'])) {
            $query->where('condition', $filters['condition']);
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
     * Show a single product by its slug.
     */
    #[OA\Get(
        path: '/products/{slug}',
        summary: 'Show a product',
        description: 'Returns a single active product by slug.',
        tags: ['products'],
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The product.', content: new OA\JsonContent(ref: '#/components/schemas/Product')),
            new OA\Response(response: 404, description: 'Product not found or inactive.'),
        ]
    )]
    public function show(string $slug)
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['images' => fn ($query) => $query->orderBy('sort_order')])
            ->firstOrFail();

        return new ProductResource($product);
    }
}
