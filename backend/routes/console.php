<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Crash-recovery safety net for the FBR outbox (normal retries self-schedule via
// FiscalizeInvoiceJob's release()). withoutOverlapping guards against a slow run
// still working when the next tick fires.
Schedule::command('fiscal:sweep-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Compliance requires outages reported to the Commissioner within 24h - check
// more often than the threshold so an alert can never be more than ~10min late.
Schedule::command('fiscal:check-pending-age')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
