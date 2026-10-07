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
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'team' => $this->team,
            'year' => $this->year,
            'description' => $this->description,
            'price' => $this->price,
            'old_price' => $this->old_price,
            'discount_percent' => $this->discount_percent,
            'stock' => $this->stock,
            'in_stock' => $this->stock > 0,
            'is_active' => $this->is_active,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
