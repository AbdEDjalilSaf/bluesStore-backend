<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'customer_name',
        'phone',
        'wilaya',
        'subtotal',
        'shipping_fee',
        'total',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'shipping_fee' => 'integer',
            'total' => 'integer',
            'status' => OrderStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Order $order): void {
            $order->recordStatusChange($order->status, $order->created_at);
        });

        static::updated(function (Order $order): void {
            if ($order->wasChanged('status')) {
                $order->recordStatusChange($order->status, $order->updated_at);
            }
        });
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('changed_at')->orderBy('id');
    }

    /**
     * Append an entry to the order status timeline.
     */
    private function recordStatusChange(OrderStatus $status, Carbon $changedAt): void
    {
        $this->statusHistories()->create([
            'status' => $status,
            'changed_at' => $changedAt,
        ]);
    }
}
