<?php

namespace App\Http\Controllers;

use App\Exceptions\NotEnoughStockException;
use App\Exceptions\ProductUnavailableException;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
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

        $order = Order::query()
            ->where('order_number', $data['order_number'])
            ->where('phone', $data['phone'])
            ->with(['items', 'statusHistories'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return new OrderTrackingResource($order);
    }
}
