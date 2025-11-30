<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Process payment webhook with idempotency
     * Handles duplicate webhooks and out-of-order delivery
     */
    public function processWebhook(
        string $idempotencyKey,
        string $orderId,
        string $paymentStatus,
        array $payload = []
    ): array {
        $startTime = microtime(true);
        
        return DB::transaction(function () use ($idempotencyKey, $orderId, $paymentStatus, $payload, $startTime) {
            // Check for existing webhook with this idempotency key
            $existingWebhook = PaymentWebhook::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            
            if ($existingWebhook) {
                Log::info('Duplicate webhook detected', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'already_processed' => $existingWebhook->isProcessed(),
                ]);
                
                return [
                    'status' => 'duplicate',
                    'webhook_id' => $existingWebhook->id,
                    'processing_status' => $existingWebhook->processing_status,
                    'order_status' => $existingWebhook->order->status ?? 'unknown',
                ];
            }
            
            // Find or wait for order (handles out-of-order webhook)
            $order = $this->findOrWaitForOrder($orderId);
            
            if (!$order) {
                // Record webhook for later processing
                $webhook = PaymentWebhook::create([
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'payment_status' => $paymentStatus,
                    'processing_status' => PaymentWebhook::PROCESSING_PENDING,
                    'payload' => $payload,
                ]);
                
                Log::warning('Order not found for webhook, stored for later', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'webhook_id' => $webhook->id,
                ]);
                
                return [
                    'status' => 'pending',
                    'message' => 'Order not yet created, webhook stored',
                    'webhook_id' => $webhook->id,
                ];
            }
            
            // Process the payment
            return $this->processPaymentForOrder($order, $idempotencyKey, $paymentStatus, $payload, $startTime);
            
        }, 5); // 5 attempts for deadlock
    }

    /**
     * Find order with brief wait for out-of-order webhooks
     */
    private function findOrWaitForOrder(string $orderId, int $maxAttempts = 3): ?Order
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $order = Order::lockForUpdate()->find($orderId);
            if ($order) {
                return $order;
            }
            
            if ($i < $maxAttempts - 1) {
                // Brief wait before retry
                usleep(100000); // 100ms
            }
        }
        
        return null;
    }

    /**
     * Process payment result for an order
     */
    private function processPaymentForOrder(
        Order $order,
        string $idempotencyKey,
        string $paymentStatus,
        array $payload,
        float $startTime
    ): array {
        // Create webhook record
        $webhook = PaymentWebhook::create([
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'payment_status' => $paymentStatus,
            'processing_status' => PaymentWebhook::PROCESSING_PENDING,
            'payload' => $payload,
        ]);
        
        // Check if order is already finalized
        if ($order->isFinalized()) {
            $webhook->processing_status = PaymentWebhook::PROCESSING_PROCESSED;
            $webhook->save();
            
            Log::info('Order already finalized, webhook acknowledged', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $order->id,
                'order_status' => $order->status,
            ]);
            
            return [
                'status' => 'already_finalized',
                'order_status' => $order->status,
                'webhook_id' => $webhook->id,
            ];
        }
        
        // Apply payment result
        if ($paymentStatus === PaymentWebhook::PAYMENT_SUCCESS) {
            $this->orderService->markAsPaid($order);
        } else {
            $this->orderService->cancelOrder($order);
        }
        
        $webhook->processing_status = PaymentWebhook::PROCESSING_PROCESSED;
        $webhook->save();
        
        $processingTime = round((microtime(true) - $startTime) * 1000, 2);
        
        Log::info('Payment webhook processed', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'payment_status' => $paymentStatus,
            'new_order_status' => $order->fresh()->status,
            'processing_time_ms' => $processingTime,
        ]);
        
        return [
            'status' => 'processed',
            'order_id' => $order->id,
            'order_status' => $order->fresh()->status,
            'webhook_id' => $webhook->id,
            'processing_time_ms' => $processingTime,
        ];
    }

    /**
     * Process pending webhooks (for orders that arrived after webhook)
     */
    public function processPendingWebhooks(): int
    {
        $count = 0;
        
        PaymentWebhook::where('processing_status', PaymentWebhook::PROCESSING_PENDING)
            ->chunk(100, function ($webhooks) use (&$count) {
                foreach ($webhooks as $webhook) {
                    try {
                        DB::transaction(function () use ($webhook, &$count) {
                            $freshWebhook = PaymentWebhook::lockForUpdate()->find($webhook->id);
                            
                            if ($freshWebhook->isProcessed()) {
                                return;
                            }
                            
                            $order = Order::lockForUpdate()->find($freshWebhook->order_id);
                            if (!$order) {
                                return; // Still waiting
                            }
                            
                            if (!$order->isFinalized()) {
                                if ($freshWebhook->payment_status === PaymentWebhook::PAYMENT_SUCCESS) {
                                    $this->orderService->markAsPaid($order);
                                } else {
                                    $this->orderService->cancelOrder($order);
                                }
                            }
                            
                            $freshWebhook->processing_status = PaymentWebhook::PROCESSING_PROCESSED;
                            $freshWebhook->save();
                            
                            Log::info('Pending webhook processed', [
                                'webhook_id' => $freshWebhook->id,
                                'order_id' => $order->id,
                            ]);
                            
                            $count++;
                        });
                    } catch (\Exception $e) {
                        Log::error('Failed to process pending webhook', [
                            'webhook_id' => $webhook->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        
        return $count;
    }
}
