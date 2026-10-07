<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\WilayaController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/wilayas', [WilayaController::class, 'index']);
