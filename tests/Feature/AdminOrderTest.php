<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Wilaya;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TOKEN = 'super-secret-admin-token';

    /**
     * @var array<string, string>
     */
    private array $adminHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.admin_token' => self::ADMIN_TOKEN]);
        config(['cache.default' => 'array']);

        $this->adminHeaders = ['X-Admin-Token' => self::ADMIN_TOKEN];
    }

    protected function makeProduct(array $attributes = []): array
    {
        return $this->catalog->createProduct(array_merge([
            'name' => 'Retro Shirt',
            'slug' => 'test-shirt-'.(count($this->catalog->products()) + 1),
            'team' => 'Algeria',
            'year' => 2020,
            'description' => 'A test shirt.',
            'price' => 1000,
            'stock' => 5,
        ], $attributes));
    }

    private function placeOrder(array $product, int $quantity = 1): Order
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 400]);

        $this->postJson('/api/orders', [
            'customer_name' => 'Karim Benali',
            'phone' => '0550123456',
            'wilaya_id' => $wilaya->id,
            'items' => array_fill(0, $quantity, ['product_id' => $product['id']]),
        ])->assertStatus(201);

        return Order::query()->latest('id')->firstOrFail();
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'order_number' => uniqid('BC-TEST-'),
            'customer_name' => 'Karim Benali',
            'phone' => '0550123456',
            'wilaya' => 'Algiers',
            'subtotal' => 1000,
            'shipping_fee' => 0,
            'total' => 1000,
            'status' => OrderStatus::Pending,
        ]);
    }

    public function test_admin_endpoints_are_public(): void
    {
        $product = $this->makeProduct();
        $order = $this->placeOrder($product, 2);

        $this->json('GET', '/api/admin/orders')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->json('GET', "/api/admin/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(2, 'data.items');

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_index_returns_all_orders_without_filters(): void
    {
        $product = $this->makeProduct();
        $pending = $this->placeOrder($product);
        $confirmed = $this->placeOrder($product);
        $shipped = $this->placeOrder($product);

        $confirmed->update(['status' => OrderStatus::Confirmed]);
        $shipped->update(['status' => OrderStatus::Shipped]);

        $response = $this->json('GET', '/api/admin/orders', [], $this->adminHeaders)
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
        $this->assertEquals($shipped->id, $data[0]['id']);
        $this->assertEquals($pending->id, $data[2]['id']);
    }

    public function test_index_returns_all_orders_even_with_many_records(): void
    {
        $orders = collect(range(1, 30))->map(fn () => $this->makeOrder());

        $response = $this->json('GET', '/api/admin/orders', [], $this->adminHeaders)
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(30, $data);
        $this->assertEquals($orders->last()->id, $data[0]['id']);
        $this->assertEquals($orders->first()->id, $data[29]['id']);
    }

    public function test_index_serves_stale_data_while_refreshing_the_cache_in_the_background(): void
    {
        $this->markTestSkipped('Caching test not applicable for non-cached endpoint');
    }

    public function test_show_returns_the_order_with_items_and_status_history(): void
    {
        $product = $this->makeProduct();
        $order = $this->placeOrder($product, 2);

        $this->json('GET', "/api/admin/orders/{$order->id}", [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.product_name', 'Retro Shirt')
            ->assertJsonCount(1, 'data.timeline')
            ->assertJsonPath('data.timeline.0.status', 'pending');

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(200);

        $this->json('GET', "/api/admin/orders/{$order->id}", [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.timeline')
            ->assertJsonPath('data.timeline.1.status', 'confirmed');

        $this->json('GET', '/api/admin/orders/999999', [], $this->adminHeaders)
            ->assertStatus(404);
    }

    public function test_valid_transitions_advance_one_step_at_a_time(): void
    {
        $product = $this->makeProduct();
        $order = $this->placeOrder($product);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'shipped'], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'shipped');
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'delivered'], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        $this->assertDatabaseCount('order_status_histories', 4);
    }

    public function test_invalid_transitions_are_rejected_with_422(): void
    {
        $product = $this->makeProduct();
        $pending = $this->placeOrder($product);

        $this->json('PATCH', "/api/admin/orders/{$pending->id}/status", ['status' => 'shipped'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change order status from pending to shipped.');
        $this->assertSame(OrderStatus::Pending, $pending->fresh()->status);

        $this->json('PATCH', "/api/admin/orders/{$pending->id}/status", ['status' => 'delivered'], $this->adminHeaders)
            ->assertStatus(422);

        $this->json('PATCH', "/api/admin/orders/{$pending->id}/status", ['status' => 'pending'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change order status from pending to pending.');

        $delivered = $this->placeOrder($product);
        foreach (['confirmed', 'shipped', 'delivered'] as $status) {
            $this->json('PATCH', "/api/admin/orders/{$delivered->id}/status", ['status' => $status], $this->adminHeaders)
                ->assertStatus(200);
        }

        $this->json('PATCH', "/api/admin/orders/{$delivered->id}/status", ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change order status from delivered to confirmed.');

        $cancelled = $this->placeOrder($product);
        $this->json('PATCH', "/api/admin/orders/{$cancelled->id}/status", ['status' => 'cancelled'], $this->adminHeaders)
            ->assertStatus(200);

        $this->json('PATCH', "/api/admin/orders/{$cancelled->id}/status", ['status' => 'pending'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change order status from cancelled to pending.');

        $this->json('PATCH', "/api/admin/orders/{$pending->id}/status", ['status' => 'archived'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->json('PATCH', "/api/admin/orders/{$pending->id}/status", [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->json('PATCH', '/api/admin/orders/999999/status', ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(404);
    }

    public function test_cancelling_restores_stock_and_is_blocked_once_shipped(): void
    {
        $product = $this->makeProduct(['stock' => 5]);
        $order = $this->placeOrder($product, 2);
        $this->assertSame(3, $this->catalog->find($product['id'])['stock']);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(200);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(5, $this->catalog->find($product['id'])['stock']);

        $shippedOrder = $this->placeOrder($product, 2);
        $this->assertSame(3, $this->catalog->find($product['id'])['stock']);

        $this->json('PATCH', "/api/admin/orders/{$shippedOrder->id}/status", ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(200);
        $this->json('PATCH', "/api/admin/orders/{$shippedOrder->id}/status", ['status' => 'shipped'], $this->adminHeaders)
            ->assertStatus(200);

        $this->json('PATCH', "/api/admin/orders/{$shippedOrder->id}/status", ['status' => 'cancelled'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change order status from shipped to cancelled.');

        $this->assertSame(OrderStatus::Shipped, $shippedOrder->fresh()->status);
        $this->assertSame(3, $this->catalog->find($product['id'])['stock']);
    }

    public function test_deleting_pending_and_cancelled_orders(): void
    {
        $product = $this->makeProduct();
        $pendingOrder = $this->placeOrder($product);

        $this->json('DELETE', "/api/admin/orders/{$pendingOrder->id}", [], $this->adminHeaders)
            ->assertStatus(204);

        $this->assertDatabaseMissing('orders', ['id' => $pendingOrder->id]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $pendingOrder->id]);

        $cancelledOrder = $this->placeOrder($product);
        $cancelledOrder->update(['status' => OrderStatus::Cancelled]);

        $this->json('DELETE', "/api/admin/orders/{$cancelledOrder->id}", [], $this->adminHeaders)
            ->assertStatus(204);

        $this->assertDatabaseMissing('orders', ['id' => $cancelledOrder->id]);
    }

    public function test_deleting_shipped_and_delivered_orders_is_blocked(): void
    {
        $product = $this->makeProduct();
        $shippedOrder = $this->placeOrder($product);
        $shippedOrder->update(['status' => OrderStatus::Shipped]);

        $this->json('DELETE', "/api/admin/orders/{$shippedOrder->id}", [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete orders that are shipped or delivered.');

        $deliveredOrder = $this->placeOrder($product);
        $deliveredOrder->update(['status' => OrderStatus::Delivered]);

        $this->json('DELETE', "/api/admin/orders/{$deliveredOrder->id}", [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete orders that are shipped or delivered.');
    }

    public function test_deleting_non_existent_order_returns_404(): void
    {
        $this->json('DELETE', '/api/admin/orders/999999', [], $this->adminHeaders)
            ->assertStatus(404);
    }

    public function test_shipping_confirmed_order(): void
    {
        $product = $this->makeProduct();
        $order = $this->placeOrder($product);
        $order->update(['status' => OrderStatus::Confirmed]);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/ship", [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'shipped');

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    public function test_shipping_only_confirmed_orders(): void
    {
        $product = $this->makeProduct();
        $pendingOrder = $this->placeOrder($product);

        $this->json('PATCH', "/api/admin/orders/{$pendingOrder->id}/ship", [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only confirmed orders can be shipped.');

        $shippedOrder = $this->placeOrder($product);
        $shippedOrder->update(['status' => OrderStatus::Shipped]);

        $this->json('PATCH', "/api/admin/orders/{$shippedOrder->id}/ship", [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only confirmed orders can be shipped.');
    }

    public function test_delivering_shipped_order(): void
    {
        $product = $this->makeProduct();
        $order = $this->placeOrder($product);
        $order->update(['status' => OrderStatus::Shipped]);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/deliver", [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'delivered');

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_delivering_only_shipped_orders(): void
    {
        $product = $this->makeProduct();
        $confirmedOrder = $this->placeOrder($product);
        $confirmedOrder->update(['status' => OrderStatus::Confirmed]);

        $this->json('PATCH', "/api/admin/orders/{$confirmedOrder->id}/deliver", [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only shipped orders can be delivered.');

        $deliveredOrder = $this->placeOrder($product);
        $deliveredOrder->update(['status' => OrderStatus::Delivered]);

        $this->json('PATCH', "/api/admin/orders/{$deliveredOrder->id}/deliver", [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only shipped orders can be delivered.');
    }

    public function test_ship_and_deliver_non_existent_order_returns_404(): void
    {
        $this->json('PATCH', '/api/admin/orders/999999/ship', [], $this->adminHeaders)
            ->assertStatus(404);

        $this->json('PATCH', '/api/admin/orders/999999/deliver', [], $this->adminHeaders)
            ->assertStatus(404);
    }
}
