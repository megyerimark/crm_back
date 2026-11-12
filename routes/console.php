<?php

use App\Http\Controllers\Api\V1\Admin\SystemController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crm:send-task-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('crm:prune-import-files')->hourly();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('crm:send-trial-reminders')->dailyAt('09:12');
Schedule::command('crm:send-daily-digest')->hourlyAt(2)->withoutOverlapping();
Schedule::call(fn () => cache()->forever(SystemController::HEARTBEAT_KEY, now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
