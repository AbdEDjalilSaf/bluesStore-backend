<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Order',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'order_number', type: 'string', example: 'BC-20261007-AB12'),
        new OA\Property(property: 'customer_name', type: 'string'),
        new OA\Property(property: 'phone', type: 'string'),
        new OA\Property(property: 'wilaya', type: 'string'),
        new OA\Property(property: 'subtotal', type: 'integer'),
        new OA\Property(property: 'shipping_fee', type: 'integer'),
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled']),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItem')),
        new OA\Property(property: 'timeline', type: 'array', description: 'Status history. Present only when the order history is loaded, e.g. on the admin order detail.', items: new OA\Items(ref: '#/components/schemas/OrderStatusChange')),
        new OA\Property(property: 'created_at', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'OrderCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Order')),
        new OA\Property(property: 'links', type: 'object'),
        new OA\Property(property: 'meta', type: 'object'),
    ]
)]
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'wilaya' => $this->wilaya,
            'subtotal' => $this->subtotal,
            'shipping_fee' => $this->shipping_fee,
            'total' => $this->total,
            'status' => $this->status?->value,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'timeline' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'created_at' => $this->created_at,
        ];
    }
}
