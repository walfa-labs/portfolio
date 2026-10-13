<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('prune:orphan-tags')->dailyAt('00:00');

// Media orphans: abandoned uploads, failed rows and rows whose owner is gone
// (§9.8). The age windows live on the command itself.
Schedule::command('media:prune')->daily();

// Contact messages are retained for 24 months (§8.8). The window itself lives
// on `ContactMessage::prunable()`; this only drives the sweep.
Schedule::command('model:prune')->daily();
