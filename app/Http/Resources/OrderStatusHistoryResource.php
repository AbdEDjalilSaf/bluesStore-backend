<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderStatusChange',
    type: 'object',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled']),
        new OA\Property(property: 'changed_at', type: 'string', example: '2026-10-07T14:03:00.000000Z'),
    ]
)]
class OrderStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status?->value,
            'changed_at' => $this->changed_at,
        ];
    }
}
