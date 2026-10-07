<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TrackedOrder',
    type: 'object',
    properties: [
        new OA\Property(property: 'order_number', type: 'string', example: 'BC-20261007-AB12'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled']),
        new OA\Property(property: 'timeline', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderStatusChange')),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItem')),
        new OA\Property(property: 'subtotal', type: 'integer'),
        new OA\Property(property: 'shipping_fee', type: 'integer'),
        new OA\Property(property: 'total', type: 'integer'),
    ]
)]
class OrderTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'status' => $this->status?->value,
            'timeline' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'subtotal' => $this->subtotal,
            'shipping_fee' => $this->shipping_fee,
            'total' => $this->total,
        ];
    }
}
