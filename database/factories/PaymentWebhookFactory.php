<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\PaymentWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentWebhookFactory extends Factory
{
    protected $model = PaymentWebhook::class;

    public function definition(): array
    {
        return [
            'idempotency_key' => Str::uuid()->toString(),
            'order_id' => Order::factory(),
            'payment_status' => PaymentWebhook::PAYMENT_SUCCESS,
            'processing_status' => PaymentWebhook::PROCESSING_PENDING,
            'payload' => [],
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processing_status' => PaymentWebhook::PROCESSING_PROCESSED,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => PaymentWebhook::PAYMENT_FAILED,
        ]);
    }
}
