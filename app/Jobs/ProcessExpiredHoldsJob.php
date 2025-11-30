<?php

namespace App\Jobs;

use App\Services\HoldService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessExpiredHoldsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the unique lock should be maintained.
     */
    public int $uniqueFor = 60;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 5;

    /**
     * Execute the job.
     */
    public function handle(HoldService $holdService): void
    {
        $startTime = microtime(true);
        
        try {
            $count = $holdService->processExpiredHolds();
            
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info('ProcessExpiredHoldsJob completed', [
                'expired_count' => $count,
                'processing_time_ms' => $processingTime,
            ]);
            
        } catch (\Exception $e) {
            Log::error('ProcessExpiredHoldsJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'process-expired-holds';
    }
}
