<?php

namespace App\Http\Controllers;

use App\Exceptions\NotEnoughStockException;
use App\Exceptions\ProductUnavailableException;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
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
}
