<?php

namespace App\Services;

use App\Models\Wilaya;

class ShippingService
{
    /**
     * Calculate the shipping fee for a given wilaya and cart subtotal.
     * Shipping is free at or above the configured threshold.
     */
    public static function calculate(Wilaya $wilaya, int $subtotal): int
    {
        if ($subtotal >= (int) config('shop.free_shipping_threshold')) {
            return 0;
        }

        return $wilaya->shipping_fee;
    }
}
