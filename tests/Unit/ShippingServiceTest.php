<?php

namespace Tests\Unit;

use App\Models\Wilaya;
use App\Services\ShippingService;
use Tests\TestCase;

class ShippingServiceTest extends TestCase
{
    public function test_returns_free_shipping_at_or_above_the_threshold(): void
    {
        $wilaya = new Wilaya(['name' => 'Oran', 'shipping_fee' => 500]);

        $this->assertSame(0, ShippingService::calculate($wilaya, 10000));
        $this->assertSame(0, ShippingService::calculate($wilaya, 15000));
    }

    public function test_charges_the_wilaya_fee_below_the_threshold(): void
    {
        $wilaya = new Wilaya(['name' => 'Tamanrasset', 'shipping_fee' => 800]);

        $this->assertSame(800, ShippingService::calculate($wilaya, 0));
        $this->assertSame(800, ShippingService::calculate($wilaya, 9999));
    }

    public function test_uses_the_configured_threshold(): void
    {
        config(['shop.free_shipping_threshold' => 5000]);

        $wilaya = new Wilaya(['name' => 'Algiers', 'shipping_fee' => 400]);

        $this->assertSame(0, ShippingService::calculate($wilaya, 5000));
        $this->assertSame(400, ShippingService::calculate($wilaya, 4999));
    }
}
