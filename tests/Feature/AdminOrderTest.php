<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
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

        $this->adminHeaders = ['X-Admin-Token' => self::ADMIN_TOKEN];
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Retro Shirt',
            'price' => 1000,
            'stock' => 5,
        ], $attributes));
    }

    private function placeOrder(Product $product, int $quantity = 1): Order
    {
        $wilaya = Wilaya::factory()->create(['shipping_fee' => 400]);

        $this->postJson('/api/orders', [
            'customer_name' => 'Karim Benali',
            'phone' => '0550123456',
            'wilaya_id' => $wilaya->id,
            'items' => array_fill(0, $quantity, ['product_id' => $product->id]),
        ])->assertStatus(201);

        return Order::query()->latest('id')->firstOrFail();
    }

    public function test_admin_endpoints_require_a_valid_admin_token(): void
    {
        $product = $this->makeProduct();
        $order = $this->placeOrder($product, 2);

        $this->json('GET', '/api/admin/orders')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');

        $this->json('GET', "/api/admin/orders/{$order->id}")
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthorized.');

        $this->json('GET', '/api/admin/orders', [], ['X-Admin-Token' => 'wrong-token'])
            ->assertStatus(401);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'], ['X-Admin-Token' => 'wrong-token'])
            ->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);

        $this->json('GET', '/api/admin/orders', [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_is_paginated_and_filters_by_status(): void
    {
        $product = $this->makeProduct();
        $pending = $this->placeOrder($product);
        $confirmed = $this->placeOrder($product);
        $shipped = $this->placeOrder($product);

        $confirmed->update(['status' => OrderStatus::Confirmed]);
        $shipped->update(['status' => OrderStatus::Shipped]);

        $this->json('GET', '/api/admin/orders', [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'order_number', 'status', 'items']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $shipped->id)
            ->assertJsonPath('data.2.id', $pending->id);

        $this->json('GET', '/api/admin/orders?status=pending', [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', 'pending');

        $this->json('GET', '/api/admin/orders?status=confirmed', [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $confirmed->id);

        $this->json('GET', '/api/admin/orders?status=cancelled', [], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->json('GET', '/api/admin/orders?status=archived', [], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
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
        $this->assertSame(3, $product->fresh()->stock);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(200);

        $this->json('PATCH', "/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'], $this->adminHeaders)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);

        $shippedOrder = $this->placeOrder($product, 2);
        $this->assertSame(3, $product->fresh()->stock);

        $this->json('PATCH', "/api/admin/orders/{$shippedOrder->id}/status", ['status' => 'confirmed'], $this->adminHeaders)
            ->assertStatus(200);
        $this->json('PATCH', "/api/admin/orders/{$shippedOrder->id}/status", ['status' => 'shipped'], $this->adminHeaders)
            ->assertStatus(200);

        $this->json('PATCH', "/api/admin/orders/{$shippedOrder->id}/status", ['status' => 'cancelled'], $this->adminHeaders)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change order status from shipped to cancelled.');

        $this->assertSame(OrderStatus::Shipped, $shippedOrder->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);
    }
}
