<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// تنظيف التوكنات المنتهية (لازم الـ scheduler يكون شغال: * * * * * php artisan schedule:run)
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// الحجوزات المؤكدة بتمسك الطاولة لما يقرب موعدها (شوف App\Console\Commands\SyncReservationTables)
Schedule::command('reservations:sync-tables')->everyMinute();

// مفاتيح Idempotency أقدم من 24 ساعة (شوف App\Http\Middleware\EnforceIdempotency)
Schedule::command('idempotency:prune')->hourly();

// الورديات المنسية: بتنسكر تلقائياً بعد shift_max_hours (شوف App\Console\Commands\AutoCloseShifts)
Schedule::command('shifts:auto-close')->everyFifteenMinutes();
