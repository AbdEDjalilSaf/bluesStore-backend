<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\NotEnoughStockException;
use App\Exceptions\ProductUnavailableException;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    /**
     * Seconds an admin order page is served from cache without recalculation.
     * Mutations rotate the list cache version, so this is a safety net, not
     * the main freshness mechanism.
     */
    private const ORDERS_CACHE_FRESH_SECONDS = 30;

    /**
     * Extra seconds a cached admin order page may be served stale while a
     * deferred refresh repopulates it in the background, after the response
     * has been sent.
     */
    private const ORDERS_CACHE_STALE_SECONDS = 300;

    /**
     * Only the columns the resource actually reads — avoids fetching/transferring
     * the whole row on every list request.
     *
     * @var list<string>
     */
    private const ORDER_COLUMNS = [
        'id',
        'order_number',
        'customer_name',
        'phone',
        'wilaya',
        'subtotal',
        'shipping_fee',
        'total',
        'status',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    private const ORDER_ITEM_COLUMNS = [
        'id',
        'order_id',
        'product_id',
        'product_name',
        'unit_price',
    ];

    public function __construct(private readonly OrderService $orderService) {}

    /**
     * Place a new order.
     */
    #[OA\Post(
        path: '/orders',
        summary: 'Place an order',
        description: 'Guest checkout. Prices are computed on the server and stock is decremented inside a transaction. Throttled to 5 requests per minute.',
        tags: ['orders'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/OrderRequest')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Order placed.', content: new OA\JsonContent(ref: '#/components/schemas/Order')),
            new OA\Response(response: 422, description: 'Validation failed, an item is unavailable, or there is not enough stock.'),
            new OA\Response(response: 429, description: 'Too many requests.'),
        ]
    )]
    public function store(StoreOrderRequest $request)
    {
        try {
            $order = $this->orderService->place($request->validated());
        } catch (NotEnoughStockException|ProductUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new OrderResource($order->load('items')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Track an order by phone and order number.
     */
    #[OA\Get(
        path: '/orders/track',
        summary: 'Track an order',
        description: 'Returns the status, status timeline, items and totals of an order when the phone and order number both match. Every mismatch returns the same generic 404. Throttled to 5 requests per minute.',
        tags: ['orders'],
        parameters: [
            new OA\Parameter(name: 'phone', in: 'query', required: true, description: 'Algerian mobile number (0[5-7]XXXXXXXX) used when the order was placed.', schema: new OA\Schema(type: 'string', example: '0550123456')),
            new OA\Parameter(name: 'order_number', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: 'BC-20261007-AB12')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The tracked order.', content: new OA\JsonContent(ref: '#/components/schemas/TrackedOrder')),
            new OA\Response(response: 404, description: 'No order matches the given phone and order number.'),
            new OA\Response(response: 422, description: 'The phone or order number is missing or malformed.'),
            new OA\Response(response: 429, description: 'Too many requests.'),
        ]
    )]
    public function track(Request $request): JsonResponse|OrderTrackingResource
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^0[5-7][0-9]{8}$/'],
            'order_number' => ['required', 'string', 'max:255'],
        ], [
            'phone.regex' => 'The phone number must be a valid Algerian mobile number (e.g. 0550123456).',
        ]);

        $order = Cache::flexible(
            $this->trackCacheKey($data['order_number'], $data['phone']),
            [self::ORDERS_CACHE_FRESH_SECONDS, self::ORDERS_CACHE_STALE_SECONDS],
            function () use ($data) {
                return Order::query()
                    ->where('order_number', $data['order_number'])
                    ->where('phone', $data['phone'])
                    ->with(['items', 'statusHistories'])
                    ->first();
            }
        );

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return new OrderTrackingResource($order);
    }

    /**
     * Cache key for order tracking. Rotates with the list version so
     * order updates invalidate tracking caches too.
     */
    private function trackCacheKey(string $orderNumber, string $phone): string
    {
        $version = Cache::get(Order::LIST_CACHE_VERSION_KEY, 1);

        return sprintf('orders.track.%s.%s.%s', $version, $orderNumber, $phone);
    }

    /**
     * List orders with a status filter and pagination (admin).
     */
    #[OA\Get(
        path: '/admin/orders',
        summary: 'List orders (admin)',
        description: 'Paginated list of the newest orders, optionally filtered by status.',
        tags: ['orders'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Only return orders in this status.', schema: new OA\Schema(type: 'string', enum: ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'])),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of orders.', content: new OA\JsonContent(ref: '#/components/schemas/OrderCollection')),
            new OA\Response(response: 422, description: 'The status filter is not a valid order status.'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(OrderStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $filters['status'] ?? null;
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 15;

        $orders = Cache::flexible(
            $this->listCacheKey($status, $page, $perPage),
            [self::ORDERS_CACHE_FRESH_SECONDS, self::ORDERS_CACHE_STALE_SECONDS],
            function () use ($status, $page, $perPage) {
                $query = Order::query()
                    ->select(self::ORDER_COLUMNS)
                    ->with(['items' => fn ($items) => $items->select(self::ORDER_ITEM_COLUMNS)]);

                if ($status !== null) {
                    $query->where('status', $status);
                }

                return $query
                    ->orderByDesc('id')
                    ->paginate($perPage, ['*'], 'page', $page)
                    ->withQueryString();
            }
        );

        return OrderResource::collection($orders);
    }

    /**
     * Versioned cache key so a single `orders.list.version` rotation retires
     * every cached page without having to enumerate their keys.
     */
    private function listCacheKey(?string $status, int $page, int $perPage): string
    {
        $version = Cache::get(Order::LIST_CACHE_VERSION_KEY, 1);

        return sprintf('admin.orders.%s.%s.%d.%d', $version, $status ?? 'all', $page, $perPage);
    }

    /**
     * Get one order with its items and status history (admin).
     */
    #[OA\Get(
        path: '/admin/orders/{id}',
        summary: 'Get an order (admin)',
        description: 'Returns a single order with its items and full status history.',
        tags: ['orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The order.', content: new OA\JsonContent(ref: '#/components/schemas/Order')),
            new OA\Response(response: 404, description: 'Order not found.'),
        ]
    )]
    public function show(int $id): OrderResource
    {
        $order = Cache::flexible(
            $this->detailCacheKey($id),
            [self::ORDERS_CACHE_FRESH_SECONDS, self::ORDERS_CACHE_STALE_SECONDS],
            function () use ($id) {
                return Order::query()
                    ->with(['items', 'statusHistories'])
                    ->findOrFail($id);
            }
        );

        return new OrderResource($order);
    }

    /**
     * Cache key for a single order detail. Rotates with the list version
     * so order updates invalidate detail caches too.
     */
    private function detailCacheKey(int $id): string
    {
        $version = Cache::get(Order::LIST_CACHE_VERSION_KEY, 1);

        return sprintf('admin.orders.detail.%s.%d', $version, $id);
    }

    /**
     * Move an order to the next status, or cancel it (admin).
     */
    #[OA\Patch(
        path: '/admin/orders/{id}/status',
        summary: 'Update an order status (admin)',
        description: 'Advances the order one step (pending → confirmed → shipped → delivered) or cancels it while it is still pending or confirmed. Cancelling restores the stock of every item.',
        tags: ['orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'shipped', 'delivered', 'cancelled'], example: 'confirmed'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'The updated order.', content: new OA\JsonContent(ref: '#/components/schemas/Order')),
            new OA\Response(response: 404, description: 'Order not found.'),
            new OA\Response(response: 422, description: 'The status is invalid or the transition is not allowed from the current status.'),
        ]
    )]
    public function updateStatus(Request $request, int $id): OrderResource|JsonResponse
    {
        $order = Order::query()->findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(OrderStatus::class)],
        ]);

        $target = OrderStatus::from($data['status']);

        if (! $order->status->canTransitionTo($target)) {
            return response()->json([
                'message' => 'Cannot change order status from '.$order->status->value.' to '.$target->value.'.',
            ], 422);
        }

        if ($target === OrderStatus::Cancelled) {
            $this->orderService->cancel($order);
        } else {
            $order->update(['status' => $target]);
        }

        return new OrderResource($order->load(['items', 'statusHistories']));
    }
}
