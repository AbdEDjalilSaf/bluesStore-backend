<?php

namespace App\Http\Resources;

use App\Support\ProductImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductImage',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'url', type: 'string', format: 'uri'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'is_primary', type: 'boolean'),
    ]
)]
class ProductImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'path' => $this->resource['path'],
            'url' => ProductImageStorage::url((string) $this->resource['path'], $request),
            'sort_order' => $this->resource['sort_order'],
            'is_primary' => $this->resource['is_primary'],
        ];
    }
}
