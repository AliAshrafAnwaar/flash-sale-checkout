# Flash-Sale Checkout API

Laravel 12 API for flash-sale checkout with concurrency control, temporary holds, and idempotent webhooks.

## Tech Stack

| Technology | Purpose |
|------------|---------|
| Laravel 12 | API framework |
| MySQL 8+ | Database with row-level locking |
| Redis | Caching + distributed locks |
| Predis | PHP Redis client |

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/products/{id}` | Get product with available stock |
| POST | `/api/holds` | Create stock hold (2-min expiry) |
| POST | `/api/orders` | Create order from hold |
| POST | `/api/payments/webhook` | Payment webhook (idempotent) |

## Checkout Flow

```
1. GET /products/{id}     → See available stock (cached)
2. POST /holds            → Reserve stock for 2 minutes
3. POST /orders           → Convert hold to order
4. (Customer pays)        → External payment gateway
5. POST /webhook          → Update order to "paid", deduct stock
```

## How Locking Works

**Problem:** 2 customers, 1 item, both click "Buy" simultaneously.

```
TIME     CUSTOMER A                    CUSTOMER B
─────────────────────────────────────────────────────────
0ms      Get lock ✅                    WAIT ⏳
1ms      Check stock (1)               Waiting...
2ms      Create hold                   Waiting...
3ms      Release lock                  Got lock! ✅
4ms      ✅ SUCCESS                     Check stock (0)
5ms                                    ❌ REJECTED
```

**Result:** Only 1 hold created. No overselling.

## Edge Cases Handled

| Scenario | Solution |
|----------|----------|
| Race condition | Redis + MySQL locking |
| Hold expires | 409 "Hold has expired" |
| Duplicate webhook | Return cached result |
| Webhook before order | Store as pending, retry later |

## Running Tests

```bash
php artisan test                        # All tests
php artisan test --filter=ConcurrencyTest  # Locking tests
```

## Project Structure

```
app/
├── Http/Controllers/Api/   # API endpoints
├── Services/               # Business logic (HoldService, OrderService, PaymentService)
├── Models/                 # Product, Hold, Order, PaymentWebhook
└── Jobs/                   # Background jobs (expired holds, pending webhooks)
```

## Key Invariants

- **No overselling**: Stock never goes below zero
- **Hold expiry**: 2 minutes, auto-released by background job
- **Idempotency**: Same webhook key = same response
- **Order finality**: Paid/cancelled orders cannot change

---

See [README.md](README.md) for full documentation.
