<?php

namespace App\Http\Controllers;

use App\Http\Resources\WilayaResource;
use App\Models\Wilaya;

class WilayaController extends Controller
{
    /**
     * List all active shipping wilayas.
     */
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
