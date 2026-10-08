<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WilayaController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::post('/products', [ProductController::class, 'store']);
Route::get('/products/{key}', [ProductController::class, 'show']);
Route::patch('/products/{key}', [ProductController::class, 'update']);
Route::delete('/products/{key}', [ProductController::class, 'destroy']);
Route::get('/wilayas', [WilayaController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:5,1');
Route::get('/orders/track', [OrderController::class, 'track'])->middleware('throttle:5,1,order-track');

Route::get('/categories', [CategoryController::class, 'index']);
Route::post('/categories', [CategoryController::class, 'store']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);
Route::patch('/categories/{id}', [CategoryController::class, 'update']);
Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
