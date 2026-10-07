<?php

namespace App\Http\Controllers;

use App\Http\Resources\WilayaResource;
use App\Models\Wilaya;
use OpenApi\Attributes as OA;

class WilayaController extends Controller
{
    /**
     * List all active shipping wilayas.
     */
    #[OA\Get(
        path: '/wilayas',
        summary: 'List shipping wilayas',
        description: 'Returns all active shipping destinations with their phone prefix and delivery fee.',
        tags: ['wilayas'],
        responses: [
            new OA\Response(response: 200, description: 'List of active wilayas.', content: new OA\JsonContent(ref: '#/components/schemas/WilayaCollection')),
        ]
    )]
    public function index()
    {
        return WilayaResource::collection(
            Wilaya::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
        );
    }
}
