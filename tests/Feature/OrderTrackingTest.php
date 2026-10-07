<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wilaya;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function placeOrder(string $phone = '0550123456'): Order
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 400]);
        $product = Product::factory()->create(['price' => 3000, 'stock' => 5]);

        return app(OrderService::class)->place([
            'customer_name' => 'Karim Benali',
            'phone' => $phone,
            'wilaya_id' => $wilaya->id,
            'items' => [['product_id' => $product->id]],
        ]);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function track(Order $order, string $phone = '0550123456'): TestResponse
    {
        return $this->getJson('/api/orders/track?'.http_build_query([
            'phone' => $phone,
            'order_number' => $order->order_number,
        ]));
    }

    public function test_tracking_returns_status_timeline_items_and_totals_without_the_address(): void
    {
        $order = $this->placeOrder();

        $this->track($order)
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', 3000)
            ->assertJsonPath('data.shipping_fee', 400)
            ->assertJsonPath('data.total', 3400)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.unit_price', 3000)
            ->assertJsonCount(1, 'data.timeline')
            ->assertJsonPath('data.timeline.0.status', 'pending')
            ->assertJsonStructure(['data' => ['timeline' => [['status', 'changed_at']]]])
            ->assertJsonMissingPaths(['data.wilaya', 'data.customer_name', 'data.phone']);

        $this->assertDatabaseCount('order_status_histories', 1);
    }

    public function test_tracking_returns_404_with_the_same_message_for_every_mismatch(): void
    {
        $order = $this->placeOrder();
        $otherOrder = $this->placeOrder('0661234567');

        $mismatches = [
            'unknown phone' => ['phone' => '0555555555', 'order_number' => $order->order_number],
            'unknown order number' => ['phone' => $order->phone, 'order_number' => 'BC-20261007-ZZZZ'],
            'phone and order number of different orders' => ['phone' => $otherOrder->phone, 'order_number' => $order->order_number],
            'both unknown' => ['phone' => '0555555555', 'order_number' => 'BC-20261007-ZZZZ'],
        ];

        foreach ($mismatches as $credentials) {
            $this->getJson('/api/orders/track?'.http_build_query($credentials))
                ->assertStatus(404)
                ->assertExactJson(['message' => 'Order not found.']);
        }

        $this->assertDatabaseCount('order_status_histories', 2);
    }

    public function test_tracking_timeline_shows_every_status_change(): void
    {
        $order = $this->placeOrder();

        $order->update(['status' => OrderStatus::Confirmed]);
        $order->update(['status' => OrderStatus::Shipped]);
        app(OrderService::class)->cancel($order->fresh());

        $this->track($order)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonCount(4, 'data.timeline')
            ->assertJsonPath('data.timeline.0.status', 'pending')
            ->assertJsonPath('data.timeline.1.status', 'confirmed')
            ->assertJsonPath('data.timeline.2.status', 'shipped')
            ->assertJsonPath('data.timeline.3.status', 'cancelled')
            ->assertJsonStructure(['data' => ['timeline' => [['status', 'changed_at']]]]);

        $this->assertDatabaseCount('order_status_histories', 4);
    }

    public function test_tracking_returns_422_when_phone_is_missing_or_malformed(): void
    {
        $this->getJson('/api/orders/track')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'order_number']);

        $this->getJson('/api/orders/track?phone=12345&order_number=BC-20261007-AB12')
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_tracking_endpoint_is_throttled_after_five_requests(): void
    {
        config(['cache.default' => 'array']);

        $order = $this->placeOrder();

        foreach (range(1, 5) as $i) {
            $this->track($order)->assertOk();
        }

        $this->track($order)->assertStatus(429);
    }
}
