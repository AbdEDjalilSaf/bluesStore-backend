<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\NotEnoughStockException;
use App\Exceptions\ProductUnavailableException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wilaya;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly GoogleSheetsService $googleSheetsService
    ) {}

    /**
     * Place an order inside a transaction, locking product rows so that
     * concurrent orders cannot oversell the last piece.
     *
     * @param  array{customer_name: string, phone: string, wilaya_id: int, items: array<int, array{product_id: int}>}  $data
     */
    public function place(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $wilaya = Wilaya::query()
                ->where('is_active', true)
                ->findOrFail($data['wilaya_id']);

            $lines = collect($data['items'])->countBy('product_id');

            $products = Product::query()
                ->whereKey($lines->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $productId => $quantity) {
                $product = $products->get($productId);

                if (! $product || ! $product->is_active) {
                    throw new ProductUnavailableException('One of the requested products is not available.');
                }

                if ($product->stock < $quantity) {
                    throw NotEnoughStockException::forProduct($product);
                }
            }

            $orderItems = [];
            $subtotal = 0;

            foreach ($lines as $productId => $quantity) {
                $product = $products->get($productId);

                $affected = Product::query()
                    ->whereKey($product->id)
                    ->where('stock', '>=', $quantity)
                    ->decrement('stock', $quantity);

                if ($affected === 0) {
                    throw NotEnoughStockException::forProduct($product);
                }

                for ($i = 0; $i < $quantity; $i++) {
                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'unit_price' => $product->price,
                    ];
                }

                $subtotal += $product->price * $quantity;
            }

            $shippingFee = ShippingService::calculate($wilaya, $subtotal);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'customer_name' => $data['customer_name'],
                'phone' => $data['phone'],
                'wilaya' => $wilaya->name,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'total' => $subtotal + $shippingFee,
                'status' => OrderStatus::Pending,
            ]);

            $order->items()->createMany($orderItems);

            $this->syncToGoogleSheets($order);

            return $order;
        });
    }

    /**
     * Cancel an order and restore the stock of its items.
     */
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            if ($order->status === OrderStatus::Cancelled) {
                return $order;
            }

            foreach ($order->items->groupBy('product_id') as $productId => $items) {
                $product = Product::query()
                    ->whereKey($productId)
                    ->lockForUpdate()
                    ->first();

                if ($product) {
                    $product->increment('stock', $items->count());
                }
            }

            $order->update(['status' => OrderStatus::Cancelled]);

            return $order;
        });
    }

    /**
     * Permanently delete an order.
     * Stock is NOT restored. Use cancel() instead if you need to restore stock.
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException
     */
    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($order->status === OrderStatus::Shipped || $order->status === OrderStatus::Delivered) {
                throw new \RuntimeException('Cannot delete orders that are shipped or delivered.');
            }

            $order->delete();
        });
    }

    private function generateOrderNumber(): string
    {
        $prefix = 'BC-'.now()->format('Ymd').'-';

        do {
            $number = $prefix.strtoupper(Str::random(4));
        } while (Order::query()->where('order_number', $number)->exists());

        return $number;
    }

    /**
     * Sync order data to Google Sheets.
     */
    private function syncToGoogleSheets(Order $order): void
    {
        try {
            $itemsSummary = $order->items->map(fn ($item) => "{$item->product_name} ({$item->unit_price} DA)")->join(', ');

            $orderData = [
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'phone' => $order->phone,
                'wilaya' => $order->wilaya,
                'subtotal' => $order->subtotal,
                'shipping_fee' => $order->shipping_fee,
                'total' => $order->total,
                'status' => $order->status->value,
                'items_summary' => $itemsSummary,
                'created_at' => $order->created_at->toDateTimeString(),
            ];

            $this->googleSheetsService->appendOrder($orderData);
        } catch (\Exception $e) {
            Log::error('Failed to sync order to Google Sheets', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ]);
        }
    }
}
