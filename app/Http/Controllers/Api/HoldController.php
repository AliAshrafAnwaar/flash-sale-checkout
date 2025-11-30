<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    private HoldService $holdService;

    public function __construct(HoldService $holdService)
    {
        $this->holdService = $holdService;
    }

    /**
     * Create a temporary hold on stock
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $startTime = microtime(true);

        try {
            $hold = $this->holdService->createHold(
                $request->input('product_id'),
                $request->input('qty')
            );

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Hold created via API', [
                'hold_id' => $hold->id,
                'processing_time_ms' => $processingTime,
            ]);

            return response()->json([
                'hold_id' => $hold->id,
                'expires_at' => $hold->expires_at->toIso8601String(),
                'product_id' => $hold->product_id,
                'quantity' => $hold->quantity,
            ], 201);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::warning('Hold creation failed', [
                'product_id' => $request->input('product_id'),
                'qty' => $request->input('qty'),
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
            ]);

            $statusCode = str_contains($e->getMessage(), 'Insufficient stock') ? 409 : 500;

            return response()->json([
                'error' => $e->getMessage(),
            ], $statusCode);
        }
    }
}
