<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle payment webhook (idempotent)
     */
    public function handle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'idempotency_key' => 'required|string|max:255',
            'order_id' => 'required|uuid',
            'status' => 'required|in:success,failed',
            'payload' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
            ], 422);
        }

        $startTime = microtime(true);

        try {
            $result = $this->paymentService->processWebhook(
                $request->input('idempotency_key'),
                $request->input('order_id'),
                $request->input('status'),
                $request->input('payload', [])
            );

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Payment webhook handled', [
                'idempotency_key' => $request->input('idempotency_key'),
                'result_status' => $result['status'],
                'processing_time_ms' => $processingTime,
            ]);

            // Always return 200 for idempotent handling
            // The client should check the 'status' field
            return response()->json($result, 200);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('Payment webhook processing failed', [
                'idempotency_key' => $request->input('idempotency_key'),
                'order_id' => $request->input('order_id'),
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
            ]);

            return response()->json([
                'error' => 'Webhook processing failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
