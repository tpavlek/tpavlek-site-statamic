<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Keep the /fringe/sold-out report current by refreshing a few of the stalest shows every
// five minutes — the priority queue in fringe:sold-out-report picks never-pulled shows first,
// then oldest data, with per-show freshness windows tiered by scarcity (1h/3h/6h, and 30min
// for shows with a holdover performance still on sale). A small
// batch keeps each run well under the ticket site's WAF threshold, and small-but-frequent
// (rather than one big batch) spreads a backlog out naturally — e.g. everything that came
// due while the laptop was closed overnight drains gradually instead of in one bot-shaped
// burst. withoutOverlapping so a run that's mid-scrape (or backing off from a throttle) is
// never doubled up.
//
// Local only: the scraping happens on Troy's machine (production shares this file but its
// scheduler must not hit the ticket site), and the post-hook pushes the snapshot up to
// production. `then` rather than `onSuccess` on purpose — a WAF block exits non-zero but has
// already checkpointed real data worth pushing; the script itself no-ops when the file is
// unchanged since the last push.
Schedule::command('fringe:sold-out-report --batch=4')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->environments('local')
    ->then(fn () => Process::path(base_path())->run('bin/sync-sold-out-report.sh'));
