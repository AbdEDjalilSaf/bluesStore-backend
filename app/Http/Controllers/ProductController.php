<?php

namespace App\Http\Controllers;

use App\Enums\Condition;
use App\Enums\Era;
use App\Enums\Rarity;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * List products with filters, sorting and pagination.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'team' => ['sometimes', 'string', 'max:255'],
            'rarity' => ['sometimes', Rule::enum(Rarity::class)],
            'condition' => ['sometimes', Rule::enum(Condition::class)],
            'year' => ['sometimes', Rule::enum(Era::class)],
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

        if (isset($filters['year'])) {
            $query->whereBetween('year', Era::from($filters['year'])->range());
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
    public function show(string $slug)
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['images' => fn ($query) => $query->orderBy('sort_order')])
            ->firstOrFail();

        return new ProductResource($product);
    }

    /**
     * List every era option with the number of products it holds.
     */
    public function years()
    {
        $counts = Product::query()
            ->where('is_active', true)
            ->selectRaw('year, COUNT(*) as products_count')
            ->groupBy('year')
            ->pluck('products_count', 'year');

        $eras = collect(Era::cases())->map(fn (Era $era) => [
            'value' => $era->value,
            'label' => $era->label(),
            'count' => (int) $counts
                ->filter(fn ($count, $year) => $era->contains((int) $year))
                ->sum(),
        ]);

        return response()->json(['data' => $eras]);
    }
}
