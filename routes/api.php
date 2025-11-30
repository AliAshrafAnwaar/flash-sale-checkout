<?php

use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Product endpoint
Route::get('/products/{id}', [ProductController::class, 'show']);

// Hold endpoint
Route::post('/holds', [HoldController::class, 'store']);

// Order endpoint
Route::post('/orders', [OrderController::class, 'store']);

// Payment webhook endpoint
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle']);
