<?php

namespace App\Jobs;

use App\Console\Commands\FringeSoldOutReport;
use App\Fringe\ShowScraper;
use App\Fringe\TicketPage;
use App\Fringe\TicketSiteBlocked;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Re-scrape one show's availability into the sold-out snapshot, on demand — the admin refresh
 * button on /fringe/ticket-availability dispatches this so one show's numbers can be brought current
 * without waiting for the priority-queue cron.
 *
 * Updates only a show already in the snapshot (the button only appears for one that has data),
 * carrying its prior sold-out showtimes forward via ShowScraper. If the ticket site throttles
 * mid-scrape (TicketSiteBlocked) the show is left as-is and the job simply ends.
 *
 * Currently dispatched synchronously from the controller so the request can return the fresh
 * numbers; it implements ShouldQueue so it can be moved to the background later without change.
 */
class RefreshShowAvailability implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $eventId,
        public string $year,
    ) {}

    public function handle(): void
    {
        if (! Storage::exists(FringeSoldOutReport::PATH)) {
            return;
        }

        $report = json_decode(Storage::get(FringeSoldOutReport::PATH), true);
        $shows = $report['shows'] ?? [];

        $index = collect($shows)->search(
            fn (array $row) => ($row['event_id'] ?? TicketPage::eventId($row['ticket_link'] ?? '')) === $this->eventId
        );

        if ($index === false) {
            return;
        }

        try {
            $performances = ShowScraper::performances($this->eventId, $this->year, $shows[$index]['performances'] ?? []);
        } catch (TicketSiteBlocked) {
            return;
        }

        $shows[$index]['performances'] = $performances;
        $shows[$index]['pulled_at'] = now()->toIso8601String();

        $report['shows'] = $shows;
        $report['generated_at'] = collect($shows)->pluck('pulled_at')->filter()->max();

        Storage::put(FringeSoldOutReport::PATH, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
