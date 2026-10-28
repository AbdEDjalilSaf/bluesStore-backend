<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Product',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'slug', type: 'string'),
        new OA\Property(property: 'team', type: 'string'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'price', type: 'integer'),
        new OA\Property(property: 'old_price', type: 'integer', nullable: true),
        new OA\Property(property: 'discount_percent', type: 'integer'),
        new OA\Property(property: 'stock', type: 'integer'),
        new OA\Property(property: 'size', type: 'string', nullable: true),
        new OA\Property(property: 'in_stock', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'images', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductImage')),
        new OA\Property(property: 'created_at', type: 'string'),
        new OA\Property(property: 'updated_at', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'ProductCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
        new OA\Property(property: 'links', type: 'object'),
        new OA\Property(property: 'meta', type: 'object'),
    ]
)]
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'slug' => $this->resource['slug'],
            'team' => $this->resource['team'],
            'year' => $this->resource['year'],
            'description' => $this->resource['description'],
            'price' => $this->resource['price'],
            'old_price' => $this->resource['old_price'],
            'discount_percent' => $this->discountPercent(),
            'stock' => $this->resource['stock'],
            'size' => $this->resource['size'],
            'in_stock' => $this->resource['stock'] > 0,
            'is_active' => $this->resource['is_active'],
            'images' => ProductImageResource::collection($this->resource['images'] ?? []),
            'created_at' => $this->resource['created_at'],
            'updated_at' => $this->resource['updated_at'],
        ];
    }

    private function discountPercent(): int
    {
        $price = (int) $this->resource['price'];
        $oldPrice = $this->resource['old_price'];

        if (! $oldPrice || $oldPrice <= $price) {
            return 0;
        }

        return (int) round((($oldPrice - $price) / $oldPrice) * 100);
    }
}
