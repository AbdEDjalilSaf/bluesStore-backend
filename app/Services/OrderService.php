<?php

namespace App\Services;

use App\Contracts\ProductCatalog;
use App\Enums\OrderStatus;
use App\Exceptions\NotEnoughStockException;
use App\Exceptions\ProductUnavailableException;
use App\Models\Order;
use App\Models\Wilaya;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        private readonly GoogleSheetsService $googleSheetsService,
        private readonly ProductCatalog $catalog
    ) {}

    /**
     * Place an order inside a transaction. Product stock lives in Appwrite
     * TablesDB, so a shared cache lock serializes stock changes around the
     * database transaction that records the order.
     *
     * @param  array{customer_name: string, phone: string, wilaya_id: int, items: array<int, array{product_id: int}>}  $data
     */
    public function place(array $data): Order
    {
        return Cache::lock('orders:place', 10)->block(5, function () use ($data) {
            return DB::transaction(function () use ($data) {
                $wilaya = Wilaya::query()
                    ->where('is_active', true)
                    ->findOrFail($data['wilaya_id']);

                $lines = collect($data['items'])->countBy('product_id');
                $products = $this->catalog->findMany($lines->keys()->all());

                foreach ($lines as $productId => $quantity) {
                    $product = $products[(int) $productId] ?? null;

                    if (! $product || ! $product['is_active']) {
                        throw new ProductUnavailableException('One of the requested products is not available.');
                    }

                    if ($product['stock'] < $quantity) {
                        throw NotEnoughStockException::forProduct($product['name']);
                    }
                }

                $orderItems = [];
                $subtotal = 0;
                $decremented = [];

                try {
                    foreach ($lines as $productId => $quantity) {
                        $product = $products[(int) $productId];

                        if (! $this->catalog->decrementStock((string) $product['id'], $quantity)) {
                            throw NotEnoughStockException::forProduct($product['name']);
                        }

                        $decremented[] = [(string) $product['id'], $quantity];

                        for ($i = 0; $i < $quantity; $i++) {
                            $orderItems[] = [
                                'product_id' => $product['id'],
                                'product_name' => $product['name'],
                                'unit_price' => $product['price'],
                            ];
                        }

                        $subtotal += $product['price'] * $quantity;
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
                } catch (\Throwable $exception) {
                    foreach (array_reverse($decremented) as [$decrementedId, $decrementedQuantity]) {
                        $this->catalog->incrementStock($decrementedId, $decrementedQuantity);
                    }

                    throw $exception;
                }
            });
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
                $this->catalog->incrementStock((string) $productId, $items->count());
            }

            $order->update(['status' => OrderStatus::Cancelled]);

            return $order;
        });
    }

    /**
     * Mark an order as shipped (confirmed → shipped).
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException
     */
    public function ship(int $id): Order
    {
        return DB::transaction(function () use ($id) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($order->status !== OrderStatus::Confirmed) {
                throw new \RuntimeException('Only confirmed orders can be shipped.');
            }

            $order->update(['status' => OrderStatus::Shipped]);

            return $order;
        });
    }

    /**
     * Mark an order as delivered (shipped → delivered).
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException
     */
    public function deliver(int $id): Order
    {
        return DB::transaction(function () use ($id) {
            $order = Order::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($order->status !== OrderStatus::Shipped) {
                throw new \RuntimeException('Only shipped orders can be delivered.');
            }

            $order->update(['status' => OrderStatus::Delivered]);

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
