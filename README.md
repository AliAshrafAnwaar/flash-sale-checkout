# Flash-Sale Checkout API

A Laravel 12 API for handling flash-sale checkout with high concurrency, temporary holds, and idempotent payment webhooks.

---

## 🛠 Tech Stack

| Technology | Purpose |
|------------|---------|
| **Laravel 12** | PHP framework for API development |
| **MySQL 8+** | Primary database with InnoDB (supports row-level locking) |
| **Redis** | Caching layer + distributed locking |
| **Predis** | Pure PHP Redis client (no extension required) |

### Why These Technologies?

- **MySQL InnoDB**: Provides `SELECT ... FOR UPDATE` for pessimistic locking, preventing race conditions
- **Redis**: Sub-millisecond caching for stock queries under burst traffic + distributed locks for multi-server deployments
- **Laravel**: Built-in support for database transactions, cache abstraction, and job scheduling

---

## 🔄 The Complete Checkout Cycle

Here's what happens when a customer purchases during a flash sale:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           FLASH SALE CHECKOUT FLOW                          │
└─────────────────────────────────────────────────────────────────────────────┘

    Customer                    API                         Database/Redis
        │                        │                                │
        │  1. View Product       │                                │
        │───────────────────────>│                                │
        │                        │  Check Redis cache             │
        │                        │───────────────────────────────>│
        │                        │  Cache HIT: return cached      │
        │                        │<───────────────────────────────│
        │  Product + Stock       │                                │
        │<───────────────────────│                                │
        │                        │                                │
        │  2. Create Hold        │                                │
        │───────────────────────>│                                │
        │                        │  Acquire Redis Lock            │
        │                        │───────────────────────────────>│
        │                        │  BEGIN TRANSACTION             │
        │                        │  SELECT product FOR UPDATE     │
        │                        │  Check available stock         │
        │                        │  INSERT hold record            │
        │                        │  COMMIT                        │
        │                        │  Release Redis Lock            │
        │                        │  Invalidate stock cache        │
        │  Hold ID (2 min TTL)   │<───────────────────────────────│
        │<───────────────────────│                                │
        │                        │                                │
        │  3. Create Order       │                                │
        │───────────────────────>│                                │
        │                        │  Validate hold (not expired)   │
        │                        │  Mark hold as "converted"      │
        │                        │  INSERT order (pending_payment)│
        │  Order ID              │<───────────────────────────────│
        │<───────────────────────│                                │
        │                        │                                │
        │  4. Customer Pays      │                                │
        │  (external gateway)    │                                │
        │                        │                                │
        │                        │  5. Payment Webhook            │
   Payment Gateway ─────────────>│                                │
        │                        │  Check idempotency_key         │
        │                        │  (already processed? → return) │
        │                        │  Update order → "paid"         │
        │                        │  Deduct physical stock         │
        │                        │  Invalidate cache              │
        │                        │<───────────────────────────────│
        │                        │                                │
        │  ✅ Purchase Complete! │                                │
        │                        │                                │
└───────┴────────────────────────┴────────────────────────────────┴───────────┘
```

### Step-by-Step Explanation

#### Step 1: View Product (`GET /api/products/{id}`)
```
Customer wants to see the product and how many are available.

→ API checks Redis cache first (5-second TTL)
→ If cached: return immediately (fast!)
→ If not cached: query database, calculate available stock, cache it
→ Available Stock = Physical Stock - Active Holds
```

#### Step 2: Create Hold (`POST /api/holds`)
```
Customer decides to buy. We need to "reserve" the stock temporarily.

→ Acquire Redis distributed lock (prevents thundering herd)
→ Start database transaction
→ Lock the product row (SELECT ... FOR UPDATE)
→ Check: available_stock >= requested quantity?
   → NO: Return error "Insufficient stock"
   → YES: Create hold record with 2-minute expiry
→ Commit transaction
→ Release Redis lock
→ Invalidate stock cache (so others see updated availability)
→ Return hold_id to customer

⏱ Customer now has 2 minutes to complete checkout!
```

#### Step 3: Create Order (`POST /api/orders`)
```
Customer proceeds to checkout with their hold.

→ Find the hold by ID
→ Validate: Is it still active? Not expired? Not already used?
   → FAIL: Return error
   → OK: Continue
→ Mark hold as "converted" (can't be used again)
→ Create order with status "pending_payment"
→ Return order details

💳 Customer is redirected to payment gateway...
```

#### Step 4: Payment Processing (External)
```
Customer pays through Stripe/PayPal/etc.
This happens outside our API.
```

#### Step 5: Payment Webhook (`POST /api/payments/webhook`)
```
Payment gateway notifies us of the result.

→ Check idempotency_key (have we seen this before?)
   → YES: Return cached result (webhook was already processed)
   → NO: Continue
→ Find the order
   → NOT FOUND: Store webhook as "pending" (out-of-order delivery)
   → FOUND: Process it
→ If payment SUCCESS:
   → Update order status to "paid"
   → Deduct physical stock from database
   → Invalidate stock cache
→ If payment FAILED:
   → Update order status to "cancelled"
   → Release the hold (stock becomes available again)
→ Return confirmation

✅ Done! Stock is now correctly reduced.
```

---

## 🚨 Handling Edge Cases

### Race Condition: Two Customers, One Item Left
```
Stock = 1, Customer A and Customer B both try to hold at the same time.

┌────────────┐     ┌────────────┐
│ Customer A │     │ Customer B │
└─────┬──────┘     └─────┬──────┘
      │                  │
      │ POST /holds      │ POST /holds
      │ qty=1            │ qty=1
      ▼                  ▼
   ┌─────────────────────────────┐
   │     Redis Distributed Lock   │
   │   (Only ONE can enter)       │
   └─────────────────────────────┘
      │                  │
      ▼                  │ (waiting...)
   ┌─────────────┐       │
   │ A gets hold │       │
   │ Stock: 1→0  │       │
   └─────────────┘       │
      │                  ▼
      │            ┌─────────────┐
      │            │ B tries...  │
      │            │ Stock = 0   │
      │            │ ❌ REJECTED │
      │            └─────────────┘
      ▼
   ✅ A succeeds, B gets "Insufficient stock"
```

### Webhook Arrives Before Order Exists
```
Sometimes the payment gateway is faster than our API response.

1. Customer creates hold
2. Customer calls POST /orders (request in progress...)
3. Payment gateway sends webhook (arrives first!)
4. We don't have the order yet... what do we do?

Solution: Store webhook as "pending"
→ Background job checks pending webhooks every minute
→ When order finally exists, webhook gets processed
```

### Duplicate Webhooks
```
Payment gateways often retry webhooks. We must not double-charge!

Webhook 1: {idempotency_key: "abc", status: "success"} → Process it, store result
Webhook 2: {idempotency_key: "abc", status: "success"} → Return cached result
Webhook 3: {idempotency_key: "abc", status: "success"} → Return cached result

Same key = Same response, no re-processing
```

---

## Assumptions & Invariants

### Stock Invariants
- **No overselling**: Physical stock is never decremented below zero
- **Available stock** = Physical stock − Active holds (not expired, not converted)
- Holds reserve "virtual" stock; physical stock deducted only on successful payment
- Cancelled orders release holds, restoring availability

### Hold Invariants
- Holds expire after **2 minutes** (configurable in `HoldService::HOLD_DURATION_MINUTES`)
- Expired holds are auto-released by background job (runs every minute)
- Each hold can only be converted to **one order**
- Hold status transitions: `active` → `converted` | `expired` | `released`

### Order Invariants
- Orders created from valid, unexpired holds only
- Order status transitions: `pending_payment` → `paid` | `cancelled`
- Once finalized (`paid`/`cancelled`), status cannot change

### Webhook Invariants
- **Idempotency**: Same `idempotency_key` always returns same result
- **Out-of-order safe**: Webhooks arriving before order exists are stored as pending
- Duplicate webhooks return `status: duplicate` without re-processing

## How to Run

### Prerequisites
- PHP 8.2+
- Composer
- MySQL 8.0+
- Redis

### Installation

```bash
# Clone and install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate

# Configure database in .env
# DB_CONNECTION=mysql
# DB_DATABASE=flash_sale
# DB_USERNAME=root
# DB_PASSWORD=

# Run migrations and seed
php artisan migrate --seed

# Start the server
php artisan serve
```

### Running the Scheduler (for hold expiry)

```bash
# In production, add to crontab:
# * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1

# For local testing, run manually:
php artisan holds:expire
```

## 📡 API Endpoints

### 1. Get Product
```http
GET /api/products/{id}
```

**Response:**
```json
{
  "id": 1,
  "name": "Flash Sale Limited Edition Gadget",
  "description": "A highly sought-after limited edition gadget...",
  "price": "99.99",
  "available_stock": 100,
  "updated_at": "2025-11-28T11:10:26.000000Z"
}
```

---

### 2. Create Hold
```http
POST /api/holds
Content-Type: application/json

{
  "product_id": 1,
  "qty": 2
}
```

**Success Response (201):**
```json
{
  "hold_id": "019aca40-5b3d-7196-a4cf-d00c8330a0e9",
  "expires_at": "2025-11-28T11:38:46+00:00",
  "product_id": 1,
  "quantity": 2
}
```

**Error Response (409 - Insufficient Stock):**
```json
{
  "error": "Insufficient stock available"
}
```

---

### 3. Create Order
```http
POST /api/orders
Content-Type: application/json

{
  "hold_id": "019aca40-5b3d-7196-a4cf-d00c8330a0e9"
}
```

**Success Response (201):**
```json
{
  "order_id": "019aca40-7072-70de-a2b4-2d7c3d627ecb",
  "hold_id": "019aca40-5b3d-7196-a4cf-d00c8330a0e9",
  "product_id": 1,
  "quantity": 2,
  "unit_price": "99.99",
  "total_price": "199.98",
  "status": "pending_payment",
  "created_at": "2025-11-28T11:36:52+00:00"
}
```

**Error Responses:**
```json
// 404 - Hold not found
{ "error": "Hold not found" }

// 409 - Hold expired
{ "error": "Hold has expired" }

// 409 - Hold already used
{ "error": "Hold has already been converted to an order" }
```

---

### 4. Payment Webhook
```http
POST /api/payments/webhook
Content-Type: application/json

{
  "idempotency_key": "pay-abc-123",
  "order_id": "019aca40-7072-70de-a2b4-2d7c3d627ecb",
  "status": "success"
}
```

**Success Response (200):**
```json
{
  "status": "processed",
  "order_id": "019aca40-7072-70de-a2b4-2d7c3d627ecb",
  "order_status": "paid",
  "webhook_id": 1,
  "processing_time_ms": 93.96
}
```

**Duplicate Response (200):**
```json
{
  "status": "duplicate",
  "message": "Webhook already processed",
  "original_result": { ... }
}
```

**Pending Response (202 - Order not yet created):**
```json
{
  "status": "pending",
  "message": "Order not found, webhook stored for later processing",
  "webhook_id": 5
}
```

---

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/products/{id}` | Get product with available stock |
| POST | `/api/holds` | Create stock hold `{product_id, qty}` |
| POST | `/api/orders` | Create order from hold `{hold_id}` |
| POST | `/api/payments/webhook` | Payment webhook `{idempotency_key, order_id, status}` |

## Running Tests

```bash
# Create test database first
mysql -u root -e "CREATE DATABASE IF NOT EXISTS flash_sale_test"

# Run all tests
php artisan test

# Run specific test file
php artisan test --filter=FlashSaleTest

# Run concurrency tests
php artisan test --filter=ConcurrencyTest

# Run with coverage
php artisan test --coverage
```

### Test Coverage

| Scenario | Test Method |
|----------|-------------|
| Parallel holds (no oversell) | `test_parallel_holds_do_not_oversell` |
| Hold at stock boundary | `test_hold_at_stock_boundary` |
| Hold expiry releases stock | `test_hold_expiry_releases_availability` |
| Webhook idempotency | `test_webhook_idempotency_same_key_repeated` |
| Webhook before order | `test_webhook_arriving_before_order_creation` |
| Pending webhook processing | `test_pending_webhook_processed_after_order_created` |

## Logs & Metrics

### Log Locations
- **Default**: `storage/logs/laravel.log`
- **Real-time**: `php artisan pail` (Laravel Pail)

### Structured Log Events

| Event | Fields Logged |
|-------|---------------|
| Hold created | `hold_id`, `product_id`, `quantity`, `expires_at` |
| Hold expired | `hold_id` |
| Order created | `order_id`, `hold_id`, `product_id`, `total`, `processing_time_ms` |
| Order paid | `order_id`, `quantity`, `new_stock` |
| Order cancelled | `order_id`, `quantity` |
| Webhook processed | `idempotency_key`, `order_id`, `payment_status`, `processing_time_ms` |
| Webhook duplicate | `idempotency_key`, `order_id`, `already_processed` |
| Deadlock retry | `attempt`, `product_id` |
| Stock cache invalidated | `product_id` |

### Example Log Query
```bash
# Find all webhook duplicates
grep "Duplicate webhook" storage/logs/laravel.log

# Find deadlock retries
grep "Deadlock detected" storage/logs/laravel.log
```

## Concurrency Strategy

### How Locking Prevents Overselling

**Problem:** 2 customers, 1 item, both click "Buy" simultaneously.

**Without locking (BAD):**
```
Customer A: Check stock → 1 available ✓ → Create hold
Customer B: Check stock → 1 available ✓ → Create hold
Result: 2 holds for 1 item! 💥 OVERSOLD
```

**With locking (GOOD):**
```
TIME     CUSTOMER A                    CUSTOMER B
─────────────────────────────────────────────────────────
0ms      Get Redis lock ✅              Try lock → WAIT ⏳
1ms      Check stock (1 available)      Waiting...
2ms      Create hold                    Waiting...
3ms      Commit + Release lock          Got lock! ✅
4ms      ✅ SUCCESS                      Check stock (0 available)
5ms                                     ❌ REJECTED
─────────────────────────────────────────────────────────
Result: Only 1 hold created ✓
```

**Key insight:** B can only check stock AFTER A commits. B sees A's hold → correctly rejected.

### Two Layers of Protection

| Layer | Purpose | Required? |
|-------|---------|-----------|
| **Redis Lock** | Coordinates across multiple servers | Optional (for scale) |
| **MySQL `FOR UPDATE`** | Locks the row during transaction | **Essential** |

```php
// The essential pattern:
DB::transaction(function () {
    $product = Product::lockForUpdate()->find($id);  // Lock row
    $available = $product->stock - $activeHolds;     // Check
    if ($available >= $qty) {
        Hold::create([...]);                          // Insert
    }
});  // COMMIT releases lock
```

### Deadlock Handling
- Automatic retry with exponential backoff (up to 3 retries)
- Random jitter (10-50ms) between retries

### Cache Strategy
- Redis cache with 5-second TTL for available stock
- Cache invalidated on: hold create/expire, order paid/cancelled
- Ensures fast reads while maintaining correctness

## Architecture

```
app/
├── Http/Controllers/Api/
│   ├── ProductController.php    # GET /api/products/{id}
│   ├── HoldController.php       # POST /api/holds
│   ├── OrderController.php      # POST /api/orders
│   └── PaymentWebhookController.php  # POST /api/payments/webhook
├── Services/
│   ├── HoldService.php          # Hold creation, expiry processing
│   ├── OrderService.php         # Order creation, payment handling
│   └── PaymentService.php       # Webhook idempotency, processing
├── Models/
│   ├── Product.php              # Stock management, caching
│   ├── Hold.php                 # Temporary reservations
│   ├── Order.php                # Order state machine
│   └── PaymentWebhook.php       # Idempotency tracking
└── Jobs/
    ├── ProcessExpiredHoldsJob.php    # Scheduled hold cleanup
    └── ProcessPendingWebhooksJob.php # Out-of-order webhook processing
```
