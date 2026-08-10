<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Keep the /fringe/sold-out report current by refreshing a few of the stalest shows every
// ten minutes — the priority queue in fringe:sold-out-report picks never-pulled shows first,
// then oldest data. A small batch keeps each run well under the ticket site's WAF threshold;
// at 8 shows per run the whole ~212-show lineup cycles about every 4.5 hours. withoutOverlapping
// so a run that's mid-scrape (or backing off from a throttle) is never doubled up.
Schedule::command('fringe:sold-out-report --batch=8')
    ->everyTenMinutes()
    ->withoutOverlapping();
