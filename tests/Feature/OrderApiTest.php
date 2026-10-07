<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wilaya;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $items, array $extra = []): array
    {
        return array_merge([
            'customer_name' => 'Karim Benali',
            'phone' => '0550123456',
            'items' => $items,
        ], $extra);
    }

    public function test_valid_order_decrements_stock_and_snapshots_prices(): void
    {
        $wilaya = Wilaya::factory()->create(['name' => 'Algiers', 'shipping_fee' => 400]);
        $product = Product::factory()->create(['name' => 'Retro Shirt', 'price' => 3000, 'stock' => 5]);

        $response = $this->postJson('/api/orders', $this->makeOrder([
            ['product_id' => $product->id],
            ['product_id' => $product->id],
        ], ['wilaya_id' => $wilaya->id]));

        $response->assertStatus(201)
            ->assertJsonPath('data.customer_name', 'Karim Benali')
            ->assertJsonPath('data.subtotal', 6000)
            ->assertJsonPath('data.shipping_fee', 400)
            ->assertJsonPath('data.total', 6400)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.wilaya', 'Algiers')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonStructure(['data' => ['order_number', 'items' => [['product_name', 'unit_price']]]])
            ->assertJsonPath('data.items.0.product_name', 'Retro Shirt')
            ->assertJsonPath('data.items.0.unit_price', 3000);

        $this->assertMatchesRegularExpression('/^BC-\d{8}-[A-Z0-9]{4}$/', $response->json('data.order_number'));

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(1, Order::count());

        $order = Order::first();
        $this->assertSame(6000, $order->subtotal);
        $this->assertSame(6400, $order->total);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_order_above_available_stock_is_rejected(): void
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 500]);
        $product = Product::factory()->create(['price' => 2000, 'stock' => 2]);

        $response = $this->postJson('/api/orders', $this->makeOrder([
            ['product_id' => $product->id],
            ['product_id' => $product->id],
            ['product_id' => $product->id],
        ], ['wilaya_id' => $wilaya->id]));

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Not enough stock for '.$product->name.'.');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, Order::count());
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_two_orders_for_the_last_piece_cannot_both_succeed(): void
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 400]);
        $product = Product::factory()->create(['price' => 1000, 'stock' => 1]);

        $payload = $this->makeOrder([['product_id' => $product->id]], ['wilaya_id' => $wilaya->id]);

        $this->postJson('/api/orders', $payload)->assertStatus(201);
        $this->assertSame(0, $product->fresh()->stock);

        $this->postJson('/api/orders', $payload)->assertStatus(422);

        $this->assertSame(1, Order::count());
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_shipping_is_free_at_or_above_the_threshold(): void
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 500]);
        $product = Product::factory()->create(['price' => 6000, 'stock' => 5]);

        $response = $this->postJson('/api/orders', $this->makeOrder([
            ['product_id' => $product->id],
            ['product_id' => $product->id],
        ], ['wilaya_id' => $wilaya->id]));

        $response->assertStatus(201)
            ->assertJsonPath('data.subtotal', 12000)
            ->assertJsonPath('data.shipping_fee', 0)
            ->assertJsonPath('data.total', 12000);
    }

    public function test_cancel_restores_stock_and_is_idempotent(): void
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 400]);
        $product = Product::factory()->create(['price' => 1000, 'stock' => 5]);

        $this->postJson('/api/orders', $this->makeOrder([
            ['product_id' => $product->id],
            ['product_id' => $product->id],
        ], ['wilaya_id' => $wilaya->id]))->assertStatus(201);

        $order = Order::first();
        $this->assertSame(3, $product->fresh()->stock);

        $service = app(OrderService::class);

        $service->cancel($order);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertTrue($order->fresh()->status === OrderStatus::Cancelled);

        $service->cancel($order->fresh());
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_validates_customer_details(): void
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 500]);
        $product = Product::factory()->create(['price' => 1000, 'stock' => 5]);

        $this->postJson('/api/orders', $this->makeOrder(
            [['product_id' => $product->id]],
            ['wilaya_id' => $wilaya->id, 'phone' => '12345']
        ))->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->postJson('/api/orders', $this->makeOrder(
            [['product_id' => $product->id]],
            ['wilaya_id' => 999]
        ))->assertStatus(422)->assertJsonValidationErrors('wilaya_id');

        $this->postJson('/api/orders', $this->makeOrder(
            [],
            ['wilaya_id' => $wilaya->id]
        ))->assertStatus(422)->assertJsonValidationErrors('items');

        $this->postJson('/api/orders', $this->makeOrder(
            [['product_id' => 999]],
            ['wilaya_id' => $wilaya->id]
        ))->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $inactive = Product::factory()->create(['price' => 1000, 'stock' => 5, 'is_active' => false]);
        $this->postJson('/api/orders', $this->makeOrder(
            [['product_id' => $inactive->id]],
            ['wilaya_id' => $wilaya->id]
        ))->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $this->assertSame(0, Order::count());
    }

    public function test_order_endpoint_is_throttled(): void
    {
        config(['cache.default' => 'array']);

        $wilaya = Wilaya::factory()->create(['shipping_fee' => 500]);
        $product = Product::factory()->create(['price' => 1000, 'stock' => 100]);
        $payload = $this->makeOrder([['product_id' => $product->id]], ['wilaya_id' => $wilaya->id]);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/orders', $payload)->assertStatus(201);
        }

        $this->postJson('/api/orders', $payload)->assertStatus(429);

        $this->assertSame(5, Order::count());
    }
}
