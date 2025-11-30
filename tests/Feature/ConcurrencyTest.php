<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Product;
use App\Services\HoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for concurrency and race condition handling
 */
class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that parallel hold requests don't oversell
     * Simulates concurrent requests in a single process
     */
    public function test_sequential_holds_respect_stock_boundary(): void
    {
        $product = Product::create([
            'name' => 'Concurrent Test Product',
            'price' => 100.00,
            'stock' => 5,
            'version' => 1,
        ]);

        $holdService = app(HoldService::class);
        $results = ['success' => 0, 'failed' => 0];

        // Try to create 10 holds of 1 each when only 5 available
        for ($i = 0; $i < 10; $i++) {
            try {
                $holdService->createHold($product->id, 1);
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
            }
        }

        // Exactly 5 should succeed
        $this->assertEquals(5, $results['success'], 'Expected exactly 5 successful holds');
        $this->assertEquals(5, $results['failed'], 'Expected exactly 5 failed holds');

        // Verify database state
        $totalHeld = Hold::where('product_id', $product->id)
            ->where('status', Hold::STATUS_ACTIVE)
            ->sum('quantity');

        $this->assertEquals(5, $totalHeld, 'Total held quantity should equal stock');
    }

    /**
     * Test hold creation at exact stock boundary
     */
    public function test_hold_at_exact_boundary(): void
    {
        $product = Product::create([
            'name' => 'Boundary Test Product',
            'price' => 50.00,
            'stock' => 10,
            'version' => 1,
        ]);

        $holdService = app(HoldService::class);

        // Create hold for exactly all stock
        $hold = $holdService->createHold($product->id, 10);
        $this->assertNotNull($hold);
        $this->assertEquals(10, $hold->quantity);

        // Next hold should fail
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient stock');
        $holdService->createHold($product->id, 1);
    }

    /**
     * Test that multiple small holds sum correctly
     */
    public function test_multiple_small_holds_sum_correctly(): void
    {
        $product = Product::create([
            'name' => 'Sum Test Product',
            'price' => 25.00,
            'stock' => 20,
            'version' => 1,
        ]);

        $holdService = app(HoldService::class);

        // Create 4 holds of 5 each = 20 total
        for ($i = 0; $i < 4; $i++) {
            $holdService->createHold($product->id, 5);
        }

        // Verify all stock is held
        $totalHeld = Hold::where('product_id', $product->id)
            ->where('status', Hold::STATUS_ACTIVE)
            ->sum('quantity');

        $this->assertEquals(20, $totalHeld);

        // No more holds possible
        $this->expectException(\Exception::class);
        $holdService->createHold($product->id, 1);
    }

    /**
     * Test that expired holds free up stock for new holds
     */
    public function test_expired_holds_release_for_new_holds(): void
    {
        $product = Product::create([
            'name' => 'Expiry Test Product',
            'price' => 75.00,
            'stock' => 5,
            'version' => 1,
        ]);

        $holdService = app(HoldService::class);

        // Create hold for all stock
        $hold = $holdService->createHold($product->id, 5);

        // Manually expire the hold
        $hold->expires_at = now()->subMinutes(1);
        $hold->save();

        // Process expired holds
        $expiredCount = $holdService->processExpiredHolds();
        $this->assertEquals(1, $expiredCount);

        // Now we should be able to create new holds
        $newHold = $holdService->createHold($product->id, 5);
        $this->assertNotNull($newHold);
        $this->assertEquals(5, $newHold->quantity);
    }

    /**
     * Test database transaction isolation
     */
    public function test_transaction_isolation(): void
    {
        $product = Product::create([
            'name' => 'Transaction Test Product',
            'price' => 100.00,
            'stock' => 3,
            'version' => 1,
        ]);

        $holdService = app(HoldService::class);
        
        // Create first hold
        $hold1 = $holdService->createHold($product->id, 2);
        
        // Refresh product to verify no stock was physically deducted yet
        $product->refresh();
        $this->assertEquals(3, $product->stock, 'Physical stock should not change from holds');
        
        // Available stock should reflect the hold
        $available = $product->available_stock;
        $this->assertEquals(1, $available, 'Available stock should account for holds');
    }

    /**
     * Test that hold conversion doesn't affect other holds
     */
    public function test_hold_conversion_isolation(): void
    {
        $product = Product::create([
            'name' => 'Conversion Test Product',
            'price' => 50.00,
            'stock' => 10,
            'version' => 1,
        ]);

        $holdService = app(HoldService::class);

        // Create two holds
        $hold1 = $holdService->createHold($product->id, 3);
        $hold2 = $holdService->createHold($product->id, 4);

        // Convert first hold
        $holdService->convertHold($hold1);

        // Second hold should still be active
        $hold2->refresh();
        $this->assertEquals(Hold::STATUS_ACTIVE, $hold2->status);

        // Available stock: converted holds don't count (stock not yet deducted until paid)
        // Only active holds reduce availability
        $available = $product->fresh()->available_stock;
        $this->assertEquals(6, $available); // 10 - 4 (active hold2) = 6
    }

    /**
     * Test metrics logging for contention
     */
    public function test_contention_metrics_logged(): void
    {
        $product = Product::create([
            'name' => 'Metrics Test Product',
            'price' => 100.00,
            'stock' => 100,
            'version' => 1,
        ]);

        Log::shouldReceive('info')
            ->atLeast()
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Hold created' && isset($context['processing_time_ms']);
            });

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $holdService = app(HoldService::class);
        $holdService->createHold($product->id, 1);
    }
}
