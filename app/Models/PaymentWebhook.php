<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'idempotency_key',
        'order_id',
        'payment_status',
        'processing_status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    const PAYMENT_SUCCESS = 'success';
    const PAYMENT_FAILED = 'failed';

    const PROCESSING_PENDING = 'pending';
    const PROCESSING_PROCESSED = 'processed';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if this webhook has been processed
     */
    public function isProcessed(): bool
    {
        return $this->processing_status === self::PROCESSING_PROCESSED;
    }
}
