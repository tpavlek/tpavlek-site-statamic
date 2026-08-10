<?php

namespace App\Console\Commands;

use App\Fringe\FestivalUrls;
use App\Fringe\Reviews;
use App\Fringe\TicketPage;
use Illuminate\Console\Command;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Backfill show running times from the ticket site onto the entries' `duration` field. One
 * page fetch per show, saved as it goes, and shows that already have a duration are skipped —
 * a running time is a static fact, so re-running only ever fills gaps. The lineup import
 * records durations for new shows by itself; this exists for shows imported before the field
 * did. Console saves don't stamp `updated_at`, so a backfill never marks reviews "Updated".
 * Run from a machine the ticket site's WAF tolerates (not production).
 */
class FringeShowDurations extends Command
{
    protected $signature = 'fringe:durations {--year=}';

    protected $description = 'Scrape show running times from ticket pages onto review entries';

    public function handle(): int
    {
        $year = $this->option('year') ?: FestivalUrls::currentSlug();

        $shows = Reviews::all()
            ->filter(fn (EntryContract $entry) => (string) $entry->value('festival') === $year)
            ->filter(fn (EntryContract $entry) => TicketPage::eventId($entry->value('ticket_link')))
            ->reject(fn (EntryContract $entry) => $entry->value('duration'))
            ->sortBy(fn (EntryContract $entry) => mb_strtolower((string) $entry->value('title')))
            ->values();

        if ($shows->isEmpty()) {
            $this->info("Every {$year} show with a ticket link already has a duration.");

            return self::SUCCESS;
        }

        $this->line("{$shows->count()} {$year} shows without a duration. Fetching one page at a time.");

        $recorded = 0;
        $missing = [];

        foreach ($shows as $entry) {
            // Same manners as the availability scraper: single-threaded with a pause.
            usleep(750 * 1000);

            $url = (string) $entry->value('ticket_link');
            $html = TicketPage::fetch($url);

            if ($html !== null && str_contains($html, 'Human Verification')) {
                $this->warn("Ticket site WAF challenge after recording {$recorded}. Wait a while, then re-run — it resumes where it left off.");

                return self::FAILURE;
            }

            $minutes = $html !== null ? TicketPage::durationMinutes($html) : null;

            if ($minutes === null) {
                $missing[] = "{$entry->value('title')} — {$url}";

                continue;
            }

            $entry->set('duration', $minutes);
            $entry->save();
            $recorded++;
            $this->line("  {$entry->value('title')}: {$minutes} min");
        }

        $this->info("Recorded {$recorded} durations.");

        foreach ($missing as $line) {
            $this->warn("No duration found: {$line}");
        }

        return self::SUCCESS;
    }
}
