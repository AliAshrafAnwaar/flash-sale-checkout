<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HoldService
{
    const HOLD_DURATION_MINUTES = 2;
    const MAX_RETRIES = 3;
    const LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Create a hold with concurrency control
     * Uses cache lock + pessimistic DB locking to prevent overselling
     */
    public function createHold(int $productId, int $quantity): Hold
    {
        $lockKey = "hold_lock:product:{$productId}";
        $startTime = microtime(true);
        
        // Try to acquire cache lock for distributed environment
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT_SECONDS);
        
        try {
            // Wait up to 5 seconds to acquire lock (or proceed immediately if locks not supported)
            $acquired = false;
            try {
                $acquired = $lock->block(5);
            } catch (\Exception $e) {
                // Lock driver doesn't support blocking, proceed with DB-level locking only
                Log::debug('Cache lock not available, using DB locking only', [
                    'product_id' => $productId,
                ]);
                $acquired = true;
            }
            
            if (!$acquired) {
                Log::warning('Failed to acquire hold lock', [
                    'product_id' => $productId,
                    'wait_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ]);
                throw new \Exception('System busy, please retry');
            }
            
            return $this->createHoldWithRetry($productId, $quantity, $startTime);
            
        } finally {
            try {
                $lock->release();
            } catch (\Exception $e) {
                // Ignore lock release errors
            }
        }
    }

    /**
     * Internal method to create hold with deadlock retry
     */
    private function createHoldWithRetry(int $productId, int $quantity, float $startTime): Hold
    {
        $retries = 0;
        $lastException = null;

        while ($retries < self::MAX_RETRIES) {
            try {
                return DB::transaction(function () use ($productId, $quantity, $startTime) {
                    // Pessimistic lock on product row
                    $product = Product::lockForUpdate()->findOrFail($productId);
                    
                    // Calculate available stock (stock minus active holds)
                    $activeHoldsQty = Hold::where('product_id', $productId)
                        ->where('status', Hold::STATUS_ACTIVE)
                        ->where('expires_at', '>', now())
                        ->lockForUpdate()
                        ->sum('quantity');
                    
                    $availableStock = $product->stock - $activeHoldsQty;
                    
                    if ($availableStock < $quantity) {
                        Log::warning('Insufficient stock for hold', [
                            'product_id' => $productId,
                            'requested' => $quantity,
                            'available' => $availableStock,
                            'physical_stock' => $product->stock,
                            'held_qty' => $activeHoldsQty,
                        ]);
                        throw new \Exception('Insufficient stock available');
                    }
                    
                    $hold = Hold::create([
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'expires_at' => now()->addMinutes(self::HOLD_DURATION_MINUTES),
                        'status' => Hold::STATUS_ACTIVE,
                    ]);
                    
                    // Invalidate cache
                    Product::invalidateStockCache($productId);
                    
                    $processingTime = round((microtime(true) - $startTime) * 1000, 2);
                    
                    Log::info('Hold created', [
                        'hold_id' => $hold->id,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'expires_at' => $hold->expires_at,
                        'remaining_stock' => $availableStock - $quantity,
                        'processing_time_ms' => $processingTime,
                    ]);
                    
                    return $hold;
                }, 5); // 5 attempts for deadlock retry
                
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle deadlock
                if ($this->isDeadlock($e)) {
                    $retries++;
                    $lastException = $e;
                    Log::warning('Deadlock detected on hold creation, retrying', [
                        'attempt' => $retries,
                        'max_retries' => self::MAX_RETRIES,
                        'product_id' => $productId,
                    ]);
                    usleep(rand(10000, 50000)); // Random backoff 10-50ms
                    continue;
                }
                throw $e;
            }
        }

        Log::error('Max retries exceeded for hold creation', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'retries' => $retries,
        ]);
        throw $lastException ?? new \Exception('Failed to create hold after max retries');
    }

    /**
     * Release a hold and restore availability
     */
    public function releaseHold(Hold $hold): void
    {
        if ($hold->status !== Hold::STATUS_ACTIVE) {
            Log::debug('Hold already released or converted', ['hold_id' => $hold->id]);
            return;
        }

        DB::transaction(function () use ($hold) {
            $hold->lockForUpdate();
            
            if ($hold->status !== Hold::STATUS_ACTIVE) {
                return;
            }
            
            $hold->status = Hold::STATUS_RELEASED;
            $hold->save();
            
            Product::invalidateStockCache($hold->product_id);
            
            Log::info('Hold released', ['hold_id' => $hold->id]);
        });
    }

    /**
     * Process expired holds (called by scheduled job)
     */
    public function processExpiredHolds(): int
    {
        $count = 0;
        
        // Use chunking to avoid memory issues
        Hold::expiredUnprocessed()
            ->lockForUpdate()
            ->chunk(100, function ($holds) use (&$count) {
                foreach ($holds as $hold) {
                    DB::transaction(function () use ($hold, &$count) {
                        // Re-check with lock
                        $freshHold = Hold::lockForUpdate()->find($hold->id);
                        
                        if ($freshHold && 
                            $freshHold->status === Hold::STATUS_ACTIVE && 
                            $freshHold->isExpired()) {
                            
                            $freshHold->status = Hold::STATUS_EXPIRED;
                            $freshHold->save();
                            
                            Product::invalidateStockCache($freshHold->product_id);
                            
                            Log::info('Hold expired', ['hold_id' => $freshHold->id]);
                            $count++;
                        }
                    });
                }
            });
        
        if ($count > 0) {
            Log::info('Processed expired holds', ['count' => $count]);
        }
        
        return $count;
    }

    /**
     * Convert hold to order (mark as converted)
     */
    public function convertHold(Hold $hold): void
    {
        DB::transaction(function () use ($hold) {
            $hold->lockForUpdate();
            
            if ($hold->status !== Hold::STATUS_ACTIVE) {
                throw new \Exception('Hold is not active');
            }
            
            if ($hold->isExpired()) {
                $hold->status = Hold::STATUS_EXPIRED;
                $hold->save();
                throw new \Exception('Hold has expired');
            }
            
            $hold->status = Hold::STATUS_CONVERTED;
            $hold->save();
            
            Log::info('Hold converted to order', ['hold_id' => $hold->id]);
        });
    }

    private function isDeadlock(\Illuminate\Database\QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'Deadlock found');
    }
}
