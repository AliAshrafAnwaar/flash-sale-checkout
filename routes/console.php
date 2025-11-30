<?php

use App\Jobs\ProcessExpiredHoldsJob;
use App\Jobs\ProcessPendingWebhooksJob;
use App\Services\HoldService;
use App\Services\PaymentService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled Jobs
Schedule::job(new ProcessExpiredHoldsJob())->everyMinute()->withoutOverlapping();
Schedule::job(new ProcessPendingWebhooksJob())->everyMinute()->withoutOverlapping();

// Manual commands for testing
Artisan::command('holds:expire', function (HoldService $holdService) {
    $count = $holdService->processExpiredHolds();
    $this->info("Processed {$count} expired holds.");
})->purpose('Process expired holds immediately');

Artisan::command('webhooks:process', function (PaymentService $paymentService) {
    $count = $paymentService->processPendingWebhooks();
    $this->info("Processed {$count} pending webhooks.");
})->purpose('Process pending webhooks immediately');
