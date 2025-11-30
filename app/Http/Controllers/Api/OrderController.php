<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Create an order from a valid hold
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $startTime = microtime(true);

        try {
            $order = $this->orderService->createOrderFromHold(
                $request->input('hold_id')
            );

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Order created via API', [
                'order_id' => $order->id,
                'hold_id' => $request->input('hold_id'),
                'processing_time_ms' => $processingTime,
            ]);

            return response()->json([
                'order_id' => $order->id,
                'hold_id' => $order->hold_id,
                'product_id' => $order->product_id,
                'quantity' => $order->quantity,
                'unit_price' => $order->unit_price,
                'total_price' => $order->total_price,
                'status' => $order->status,
                'created_at' => $order->created_at->toIso8601String(),
            ], 201);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::warning('Order creation failed', [
                'hold_id' => $request->input('hold_id'),
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
            ]);

            $statusCode = match (true) {
                str_contains($e->getMessage(), 'not found') => 404,
                str_contains($e->getMessage(), 'expired') => 410,
                str_contains($e->getMessage(), 'not valid') => 409,
                str_contains($e->getMessage(), 'not active') => 409,
                default => 500,
            };

            return response()->json([
                'error' => $e->getMessage(),
            ], $statusCode);
        }
    }
}
