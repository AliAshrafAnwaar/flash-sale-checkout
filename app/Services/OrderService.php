<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    private HoldService $holdService;

    public function __construct(HoldService $holdService)
    {
        $this->holdService = $holdService;
    }

    /**
     * Create order from a valid hold
     */
    public function createOrderFromHold(string $holdId): Order
    {
        return DB::transaction(function () use ($holdId) {
            // Lock hold to prevent race conditions
            $hold = Hold::with('product')->lockForUpdate()->find($holdId);
            
            if (!$hold) {
                throw new \Exception('Hold not found');
            }
            
            // Check if order already exists for this hold
            $existingOrder = Order::where('hold_id', $holdId)->first();
            if ($existingOrder) {
                Log::info('Order already exists for hold', [
                    'hold_id' => $holdId,
                    'order_id' => $existingOrder->id,
                ]);
                return $existingOrder;
            }
            
            if (!$hold->isValid()) {
                if ($hold->isExpired()) {
                    // Mark hold as expired
                    $hold->status = Hold::STATUS_EXPIRED;
                    $hold->save();
                    Product::invalidateStockCache($hold->product_id);
                    throw new \Exception('Hold has expired');
                }
                throw new \Exception('Hold is not valid');
            }
            
            // Convert hold
            $this->holdService->convertHold($hold);
            
            // Create order
            $product = $hold->product;
            $order = Order::create([
                'hold_id' => $hold->id,
                'product_id' => $product->id,
                'quantity' => $hold->quantity,
                'unit_price' => $product->price,
                'total_price' => $product->price * $hold->quantity,
                'status' => Order::STATUS_PENDING_PAYMENT,
            ]);
            
            Log::info('Order created', [
                'order_id' => $order->id,
                'hold_id' => $holdId,
                'product_id' => $product->id,
                'quantity' => $hold->quantity,
                'total' => $order->total_price,
            ]);
            
            return $order;
        }, 5); // 5 attempts for deadlock
    }

    /**
     * Mark order as paid and finalize stock deduction
     */
    public function markAsPaid(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->find($order->id);
            
            if ($order->status === Order::STATUS_PAID) {
                Log::debug('Order already paid', ['order_id' => $order->id]);
                return;
            }
            
            if (!$order->canBeUpdatedByPayment()) {
                throw new \Exception("Order cannot be updated, current status: {$order->status}");
            }
            
            // Deduct from physical stock (hold was reserving virtual stock)
            $product = Product::lockForUpdate()->find($order->product_id);
            
            if ($product->stock < $order->quantity) {
                Log::error('Insufficient physical stock for paid order', [
                    'order_id' => $order->id,
                    'required' => $order->quantity,
                    'available' => $product->stock,
                ]);
                throw new \Exception('Insufficient stock');
            }
            
            // Decrement physical stock
            DB::table('products')
                ->where('id', $product->id)
                ->decrement('stock', $order->quantity);
            
            $order->status = Order::STATUS_PAID;
            $order->save();
            
            Product::invalidateStockCache($order->product_id);
            
            Log::info('Order marked as paid, stock deducted', [
                'order_id' => $order->id,
                'quantity' => $order->quantity,
                'new_stock' => $product->stock - $order->quantity,
            ]);
        });
    }

    /**
     * Cancel order and release stock
     */
    public function cancelOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->find($order->id);
            
            if ($order->status === Order::STATUS_CANCELLED) {
                Log::debug('Order already cancelled', ['order_id' => $order->id]);
                return;
            }
            
            if (!$order->canBeUpdatedByPayment()) {
                throw new \Exception("Order cannot be cancelled, current status: {$order->status}");
            }
            
            // Mark hold as released so availability is restored
            $hold = Hold::lockForUpdate()->find($order->hold_id);
            if ($hold && $hold->status === Hold::STATUS_CONVERTED) {
                $hold->status = Hold::STATUS_RELEASED;
                $hold->save();
            }
            
            $order->status = Order::STATUS_CANCELLED;
            $order->save();
            
            Product::invalidateStockCache($order->product_id);
            
            Log::info('Order cancelled, stock released', [
                'order_id' => $order->id,
                'quantity' => $order->quantity,
            ]);
        });
    }
}
