<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentWebhook;
use App\Models\Product;
use App\Services\HoldService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a product with limited stock for testing
        $this->product = Product::create([
            'name' => 'Test Flash Sale Product',
            'description' => 'Limited stock test product',
            'price' => 99.99,
            'stock' => 10,
            'version' => 1,
        ]);
    }

    // ==========================================
    // Product Endpoint Tests
    // ==========================================

    public function test_get_product_returns_correct_data(): void
    {
        $response = $this->getJson("/api/products/{$this->product->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'description',
                'price',
                'available_stock',
                'updated_at',
            ])
            ->assertJson([
                'id' => $this->product->id,
                'name' => $this->product->name,
                'available_stock' => 10,
            ]);
    }

    public function test_get_product_shows_reduced_stock_with_active_holds(): void
    {
        // Create a hold
        Hold::create([
            'product_id' => $this->product->id,
            'quantity' => 3,
            'expires_at' => now()->addMinutes(2),
            'status' => Hold::STATUS_ACTIVE,
        ]);

        // Clear cache to get fresh data
        Product::invalidateStockCache($this->product->id);

        $response = $this->getJson("/api/products/{$this->product->id}");

        $response->assertStatus(200)
            ->assertJson([
                'available_stock' => 7, // 10 - 3
            ]);
    }

    public function test_get_product_not_found(): void
    {
        $response = $this->getJson('/api/products/99999');

        $response->assertStatus(404);
    }

    // ==========================================
    // Hold Creation Tests
    // ==========================================

    public function test_create_hold_success(): void
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 2,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'hold_id',
                'expires_at',
                'product_id',
                'quantity',
            ]);

        $this->assertDatabaseHas('holds', [
            'product_id' => $this->product->id,
            'quantity' => 2,
            'status' => Hold::STATUS_ACTIVE,
        ]);
    }

    public function test_create_hold_insufficient_stock(): void
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 100, // More than available
        ]);

        $response->assertStatus(409);
    }

    public function test_create_hold_validation_error(): void
    {
        $response = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => -1,
        ]);

        $response->assertStatus(422);
    }

    public function test_multiple_holds_reduce_available_stock(): void
    {
        // Create first hold
        $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 3,
        ])->assertStatus(201);

        // Create second hold
        $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 4,
        ])->assertStatus(201);

        // Try to create third hold exceeding remaining stock
        $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 5, // Only 3 remaining (10 - 3 - 4)
        ])->assertStatus(409);

        // Verify correct hold can still be made
        $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 3, // Exactly remaining stock
        ])->assertStatus(201);
    }

    // ==========================================
    // Parallel Hold Tests (No Overselling)
    // ==========================================

    public function test_parallel_holds_do_not_oversell(): void
    {
        // Create a product with exactly 10 stock
        $product = Product::create([
            'name' => 'Parallel Test Product',
            'price' => 50.00,
            'stock' => 10,
            'version' => 1,
        ]);

        $holdService = app(HoldService::class);
        $successCount = 0;
        $failCount = 0;

        // Simulate 20 parallel hold attempts, each for 1 unit
        // Using sequential calls since PHP doesn't have true parallelism
        // In production, use actual concurrent requests or load testing tools
        for ($i = 0; $i < 20; $i++) {
            try {
                $holdService->createHold($product->id, 1);
                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
            }
        }

        // Exactly 10 should succeed, 10 should fail
        $this->assertEquals(10, $successCount, 'Expected exactly 10 successful holds');
        $this->assertEquals(10, $failCount, 'Expected exactly 10 failed holds');

        // Verify database state
        $activeHolds = Hold::where('product_id', $product->id)
            ->where('status', Hold::STATUS_ACTIVE)
            ->sum('quantity');

        $this->assertEquals(10, $activeHolds, 'Total held quantity should equal stock');
    }

    public function test_hold_at_stock_boundary(): void
    {
        // Product has 10 stock
        $holdService = app(HoldService::class);

        // Create holds totaling exactly 10
        $holdService->createHold($this->product->id, 5);
        $holdService->createHold($this->product->id, 5);

        // Next hold should fail
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient stock');

        $holdService->createHold($this->product->id, 1);
    }

    // ==========================================
    // Hold Expiry Tests
    // ==========================================

    public function test_hold_expiry_releases_availability(): void
    {
        $holdService = app(HoldService::class);

        // Create an expired hold manually
        $expiredHold = Hold::create([
            'product_id' => $this->product->id,
            'quantity' => 5,
            'expires_at' => now()->subMinutes(1),
            'status' => Hold::STATUS_ACTIVE,
        ]);

        // Process expired holds
        $count = $holdService->processExpiredHolds();
        $this->assertEquals(1, $count);

        // Verify hold is marked as expired
        $expiredHold->refresh();
        $this->assertEquals(Hold::STATUS_EXPIRED, $expiredHold->status);

        // Now we should be able to create a hold for the released stock
        $newHold = $holdService->createHold($this->product->id, 5);
        $this->assertNotNull($newHold);
    }

    public function test_expired_hold_cannot_create_order(): void
    {
        // Create an expired hold
        $hold = Hold::create([
            'product_id' => $this->product->id,
            'quantity' => 2,
            'expires_at' => now()->subMinutes(1),
            'status' => Hold::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $response->assertStatus(410); // Gone - expired
    }

    // ==========================================
    // Order Creation Tests
    // ==========================================

    public function test_create_order_from_valid_hold(): void
    {
        // Create a hold first
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 2,
        ])->assertStatus(201);

        $holdId = $holdResponse->json('hold_id');

        // Create order from hold
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdId,
        ]);

        $orderResponse->assertStatus(201)
            ->assertJsonStructure([
                'order_id',
                'hold_id',
                'product_id',
                'quantity',
                'unit_price',
                'total_price',
                'status',
            ])
            ->assertJson([
                'hold_id' => $holdId,
                'quantity' => 2,
                'status' => 'pending_payment',
            ]);

        // Hold should be converted
        $this->assertDatabaseHas('holds', [
            'id' => $holdId,
            'status' => Hold::STATUS_CONVERTED,
        ]);
    }

    public function test_cannot_create_order_from_same_hold_twice(): void
    {
        // Create a hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 1,
        ])->assertStatus(201);

        $holdId = $holdResponse->json('hold_id');

        // First order creation
        $this->postJson('/api/orders', ['hold_id' => $holdId])
            ->assertStatus(201);

        // Second order creation should return the same order (idempotent)
        $response = $this->postJson('/api/orders', ['hold_id' => $holdId]);
        $response->assertStatus(201);

        // Only one order in database
        $this->assertEquals(1, Order::where('hold_id', $holdId)->count());
    }

    public function test_create_order_from_nonexistent_hold(): void
    {
        $fakeHoldId = Str::uuid()->toString();

        $response = $this->postJson('/api/orders', [
            'hold_id' => $fakeHoldId,
        ]);

        $response->assertStatus(404);
    }

    // ==========================================
    // Payment Webhook Tests
    // ==========================================

    public function test_payment_webhook_success_marks_order_paid(): void
    {
        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 2,
        ])->assertStatus(201);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ])->assertStatus(201);

        $orderId = $orderResponse->json('order_id');
        $idempotencyKey = Str::uuid()->toString();

        // Send payment success webhook
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => 'success',
        ]);

        $webhookResponse->assertStatus(200)
            ->assertJson([
                'status' => 'processed',
                'order_status' => 'paid',
            ]);

        // Verify order status
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_PAID,
        ]);

        // Verify stock was deducted
        $this->product->refresh();
        $this->assertEquals(8, $this->product->stock); // 10 - 2
    }

    public function test_payment_webhook_failure_cancels_order(): void
    {
        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 2,
        ])->assertStatus(201);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ])->assertStatus(201);

        $orderId = $orderResponse->json('order_id');
        $holdId = $holdResponse->json('hold_id');

        // Send payment failure webhook
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => Str::uuid()->toString(),
            'order_id' => $orderId,
            'status' => 'failed',
        ]);

        $webhookResponse->assertStatus(200)
            ->assertJson([
                'status' => 'processed',
                'order_status' => 'cancelled',
            ]);

        // Verify order status
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_CANCELLED,
        ]);

        // Verify hold is released
        $this->assertDatabaseHas('holds', [
            'id' => $holdId,
            'status' => Hold::STATUS_RELEASED,
        ]);

        // Stock should remain unchanged (not deducted)
        $this->product->refresh();
        $this->assertEquals(10, $this->product->stock);
    }

    public function test_webhook_idempotency_same_key_repeated(): void
    {
        // Create order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 1,
        ])->assertStatus(201);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ])->assertStatus(201);

        $orderId = $orderResponse->json('order_id');
        $idempotencyKey = 'test-idempotency-key-123';

        // First webhook
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => 'success',
        ]);

        $response1->assertStatus(200)
            ->assertJson(['status' => 'processed']);

        // Second webhook with same key
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => 'success',
        ]);

        $response2->assertStatus(200)
            ->assertJson(['status' => 'duplicate']);

        // Third webhook with same key
        $response3 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => 'success',
        ]);

        $response3->assertStatus(200)
            ->assertJson(['status' => 'duplicate']);

        // Only one webhook record should exist
        $this->assertEquals(1, PaymentWebhook::where('idempotency_key', $idempotencyKey)->count());

        // Order should be paid only once
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_PAID,
        ]);
    }

    public function test_webhook_with_different_idempotency_keys_processed_separately(): void
    {
        // Create order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 1,
        ])->assertStatus(201);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ])->assertStatus(201);

        $orderId = $orderResponse->json('order_id');

        // First webhook
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'key-1',
            'order_id' => $orderId,
            'status' => 'success',
        ])->assertStatus(200);

        // Second webhook with different key
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'key-2',
            'order_id' => $orderId,
            'status' => 'success',
        ]);

        // Should acknowledge but not reprocess (order already finalized)
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'already_finalized',
                'order_status' => 'paid',
            ]);
    }

    public function test_webhook_arriving_before_order_creation(): void
    {
        $paymentService = app(PaymentService::class);
        $orderService = app(OrderService::class);
        $holdService = app(HoldService::class);

        // Create a hold first
        $hold = $holdService->createHold($this->product->id, 1);

        // Simulate generating an order ID before order exists
        $orderId = Str::uuid()->toString();
        $idempotencyKey = 'early-webhook-key';

        // Send webhook before order exists
        $result = $paymentService->processWebhook(
            $idempotencyKey,
            $orderId,
            'success'
        );

        // Should be stored as pending
        $this->assertEquals('pending', $result['status']);
        $this->assertDatabaseHas('payment_webhooks', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'processing_status' => 'pending',
        ]);
    }

    public function test_pending_webhook_processed_after_order_created(): void
    {
        $paymentService = app(PaymentService::class);

        // Create hold
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 1,
        ])->assertStatus(201);

        // Create order
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ])->assertStatus(201);

        $orderId = $orderResponse->json('order_id');

        // Manually create a pending webhook (simulating out-of-order)
        PaymentWebhook::create([
            'idempotency_key' => 'pending-test-key',
            'order_id' => $orderId,
            'payment_status' => 'success',
            'processing_status' => 'pending',
        ]);

        // Process pending webhooks
        $count = $paymentService->processPendingWebhooks();
        $this->assertEquals(1, $count);

        // Order should now be paid
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_PAID,
        ]);

        // Webhook should be marked as processed
        $this->assertDatabaseHas('payment_webhooks', [
            'idempotency_key' => 'pending-test-key',
            'processing_status' => 'processed',
        ]);
    }

    // ==========================================
    // Edge Cases
    // ==========================================

    public function test_finalized_order_ignores_conflicting_webhooks(): void
    {
        // Create and pay an order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 1,
        ])->assertStatus(201);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ])->assertStatus(201);

        $orderId = $orderResponse->json('order_id');

        // Pay the order
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'pay-key',
            'order_id' => $orderId,
            'status' => 'success',
        ])->assertStatus(200);

        // Try to cancel with different key
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'cancel-key',
            'order_id' => $orderId,
            'status' => 'failed',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'already_finalized',
                'order_status' => 'paid',
            ]);

        // Order should still be paid
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => Order::STATUS_PAID,
        ]);
    }

    public function test_stock_restored_after_cancelled_order(): void
    {
        $initialStock = $this->product->stock;

        // Create hold and order
        $holdResponse = $this->postJson('/api/holds', [
            'product_id' => $this->product->id,
            'qty' => 3,
        ])->assertStatus(201);

        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $holdResponse->json('hold_id'),
        ])->assertStatus(201);

        // Cancel order
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => Str::uuid()->toString(),
            'order_id' => $orderResponse->json('order_id'),
            'status' => 'failed',
        ])->assertStatus(200);

        // Clear cache
        Product::invalidateStockCache($this->product->id);

        // Stock should be available again (hold released)
        $availableStock = Product::getAvailableStockCached($this->product->id);
        $this->assertEquals($initialStock, $availableStock);
    }
}
