<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->uuid('order_id'); // No FK constraint - webhooks may arrive before order exists
            $table->enum('payment_status', ['success', 'failed']);
            $table->enum('processing_status', ['pending', 'processed'])->default('pending');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
