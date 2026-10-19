<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Order extends Model
{
    /**
     * Cache key holding the current version of the admin order list. Any write
     * to an order bumps it, which retires every cached page at once.
     */
    public const LIST_CACHE_VERSION_KEY = 'orders.list.version';

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

        // Any insert/update makes every cached admin page stale. Rotating a
        // single version key is cheaper and safer than trying to forget the
        // many (status × page × per_page) keys individually.
        static::saved(fn () => static::rotateListCacheVersion());
        static::deleted(fn () => static::rotateListCacheVersion());
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

    /**
     * Invalidate every cached admin order list page.
     */
    public static function rotateListCacheVersion(): void
    {
        Cache::forever(self::LIST_CACHE_VERSION_KEY, (string) Str::uuid());
    }
}
