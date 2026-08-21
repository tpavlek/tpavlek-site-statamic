<?php

namespace App\Console\Commands;

use App\Fringe\FestivalUrls;
use App\Fringe\PerformanceListVanished;
use App\Fringe\Reviews;
use App\Fringe\ShowScraper;
use App\Fringe\TicketAvailability;
use App\Fringe\TicketPage;
use App\Fringe\TicketSiteBlocked;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Refresh ticket availability for the festival lineup into the store the /fringe/sold-out
 * page serves.
 *
 * Not a single big scrape. Assembling the numbers takes one performances call per show plus
 * a status and a seating-plan call per performance — ~4000 upstream requests for a full
 * festival — and the ticket site sits behind an AWS WAF that starts serving a challenge page
 * once one IP asks too often (see TicketSiteBlocked). So this works as a **priority queue**:
 * each run refreshes the stalest shows first (never-pulled ahead of longest-since-pulled),
 * one request at a time via TicketAvailability, and stops the moment it's throttled, having
 * checkpointed each show as it went. Run it on a slow schedule with a small --batch and the
 * whole lineup stays covered without ever bursting.
 *
 * Availability is stored in a sidecar file keyed by show, NOT on the fringe_reviews entries.
 * Writing volatile ticketing data into an entry's markdown would rewrite the file, and
 * App\Fringe\ReviewFreshness reads file mtime to decide whether a current-festival review
 * says "Updated" — so every pull would falsely stamp every published review as updated
 * today, besides churning 200 content files in git on every run.
 *
 *   php artisan fringe:sold-out-report                 # refresh the 20 stalest shows
 *   php artisan fringe:sold-out-report --batch=5       # gentler: just the 5 stalest
 *   php artisan fringe:sold-out-report --batch=0       # everything due (overnight)
 *   php artisan fringe:sold-out-report --year=2026
 */
class FringeSoldOutReport extends Command
{
    protected $signature = 'fringe:sold-out-report
        {--year= : Festival year. Defaults to the current one.}
        {--batch=8 : Refresh this many of the stalest shows. 0 refreshes the whole lineup.}';

    protected $description = 'Refresh the stalest shows in the sold-out report, newest-data-last (priority queue).';

    public const PATH = 'fringe/sold-out-report.json';

    /**
     * How long a show's data stays fresh, tiered by how close it is to selling out: the
     * scarcer the tickets, the faster the numbers move, so the sooner it's due again.
     * Fewer than 25% of seats remaining → 1h; fewer than 50% → 3h; otherwise (or with no
     * seat data yet) → 6h. Past its window a show is due — unless it's sold out, which
     * is permanent. Tightened for the final festival weekend: with only a couple of
     * performances left per show, a hot show can sell out inside the old 3h window, and
     * the 24h default sized for a 10-day run would mean one check ever in the time left.
     *
     * A show with a holdover performance still on sale outranks all of that at 30 minutes:
     * the holdover series is a handful of single showtimes selling on a few days' notice,
     * so they move faster than any scarcity tier predicts.
     */
    private const STALE_MINUTES_HOLDOVER = 30;

    private const STALE_MINUTES_SCARCE = 60;

    private const STALE_MINUTES_SELLING = 180;

    private const STALE_MINUTES_DEFAULT = 360;

    public function handle(): int
    {
        $year = (string) ($this->option('year') ?: FestivalUrls::currentSlug());
        $batch = (int) $this->option('batch');

        // The whole lineup, not just written reviews — pending imports are the festival.
        $shows = Reviews::all()
            ->filter(fn (EntryContract $entry) => (string) $entry->value('festival') === $year)
            ->filter(fn (EntryContract $entry) => TicketPage::eventId($entry->value('ticket_link')))
            ->keyBy(fn (EntryContract $entry) => TicketPage::eventId($entry->value('ticket_link')));

        // Shows held over past the festival carry a second event id whose performances are
        // scraped into the same record, flagged `holdover` — see ShowScraper::planWithHoldover.
        $holdovers = $shows->map(fn (EntryContract $entry) => TicketPage::eventId($entry->value('holdover_ticket_link')));

        if ($shows->isEmpty()) {
            $this->error("No {$year} entries with ticket links.");

            return self::FAILURE;
        }

        // What we already know, keyed by event id. Records for shows no longer in the lineup
        // are dropped by only ever rebuilding from the current $shows.
        $store = $this->load($year);

        // Only shows that are actually due — never pulled, or pulled more than STALE_AFTER
        // hours ago — and never a show that's already completely sold out (it won't reopen).
        // The queue is stalest first: a composite sort key of "pulled_at|title" puts the
        // never-pulled ahead of everything, then oldest data, then title. Never-pulled must
        // stand in as '0', not '' — an empty timestamp makes the key start with '|', which
        // sorts AFTER every digit-leading timestamp, i.e. never-pulled dead last.
        // A plain single-key sort, deliberately — Collection::sortBy with an array of
        // closures silently sorts by the last one only, which had it ignoring staleness.
        $due = $shows->keys()
            ->filter(fn (string $eventId) => $this->isDue($eventId, $store, $holdovers[$eventId] ?? null))
            ->sortBy(fn (string $eventId) => (($store[$eventId]['pulled_at'] ?? null) ?: '0').'|'.$shows[$eventId]->value('title'))
            ->values();

        if ($due->isEmpty()) {
            $this->write($year, $shows, $store);
            $this->info('Nothing due — every show is within its freshness window (or is sold out). Covers '.$this->covered($shows, $store).'/'.$shows->count().'.');

            return self::SUCCESS;
        }

        $toRun = $batch > 0 ? $due->take($batch) : $due;

        $neverPulled = $due->filter(fn (string $id) => empty($store[$id]['pulled_at']))->count();
        $this->line("{$shows->count()} shows for {$year}: {$due->count()} due ({$neverPulled} never pulled). Refreshing {$toRun->count()} stalest, one request at a time.");

        $venues = [];
        $refreshed = 0;
        $vanished = 0;

        $bar = $this->output->createProgressBar($toRun->count());
        $bar->setFormat(' %current%/%max% [%bar%] %message%');

        foreach ($toRun as $eventId) {
            $entry = $shows[$eventId];
            $bar->setMessage((string) $entry->value('title'));
            $bar->display();

            try {
                // Carries sold-out showtimes forward from the last pull and skips cancelled
                // queries — see App\Fringe\ShowScraper.
                $performances = ShowScraper::performances($eventId, $year, $store[$eventId]['performances'] ?? [], $holdovers[$eventId] ?? null);
            } catch (TicketSiteBlocked $e) {
                // Throttled. Everything pulled so far is already checkpointed; stop cleanly.
                // Also log it — under the scheduler the console output is easy to lose, and a
                // WAF block (possibly of the whole server IP range) should be visible.
                \Illuminate\Support\Facades\Log::warning('Ticket site WAF blocked the sold-out report scrape', ['refreshed_before_block' => $refreshed]);
                $bar->finish();
                $this->newLine();
                $this->warn($e->getMessage());
                $this->warn("Refreshed {$refreshed} shows before being throttled. Wait a while, then re-run — the queue resumes with whatever's now stalest.");

                return self::FAILURE;
            } catch (PerformanceListVanished) {
                // A failed list request dressed as an empty run — storing it would wipe the
                // show's real showtimes (which happened: 13 shows on 2026-08-16). Keep the
                // record and its pulled_at untouched, so the show stays stalest and is
                // retried next run; move on to the rest of the batch.
                \Illuminate\Support\Facades\Log::warning('Sold-out report: empty performance list, kept prior data', ['title' => $entry->value('title')]);
                $vanished++;
                $bar->advance();

                continue;
            }

            $venueId = $entry->value('venue');
            $venues[$venueId] ??= EntryFacade::find($venueId)?->value('title');

            $store[$eventId] = [
                'event_id' => $eventId,
                'entry_id' => $entry->id(),
                'title' => $entry->value('title'),
                'ticket_link' => $entry->value('ticket_link'),
                'review_url' => $entry->published() ? $entry->url() : null,
                'venue' => $venues[$venueId],
                'pulled_at' => now()->toIso8601String(),
                'performances' => $performances,
            ];

            // Checkpoint after every show, so a throttle or crash costs at most one show.
            $this->write($year, $shows, $store);
            $refreshed++;

            $withSeats = collect($performances)->filter(fn (array $p) => ($p['seats_total'] ?? null) !== null);
            $offered = $withSeats->sum('seats_total');
            \Illuminate\Support\Facades\Log::info('Sold-out report: refreshed show', [
                'title' => $entry->value('title'),
                'sold_out' => collect($performances)->where('status', TicketAvailability::SOLD_OUT)->count().'/'.count($performances),
                'pct_sold' => $offered > 0 ? round(100 * (1 - $withSeats->sum('seats_free') / $offered)) : null,
                'next_check_after' => $this->staleAfterMinutes($performances).'m',
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->write($year, $shows, $store);
        $this->info("Refreshed {$refreshed} shows. ".self::PATH.' now covers '.$this->covered($shows, $store).'/'.$shows->count().' of the lineup.');

        if ($vanished > 0) {
            $this->warn("{$vanished} show(s) returned an empty performance list — prior data kept, they'll retry next run.");
        }

        return self::SUCCESS;
    }

    /**
     * Whether a show needs refreshing this run:
     *
     *   - never pulled             → yes, always.
     *   - terminal run             → no, ever. Every showtime sold out or cancelled: sold
     *                                out won't reopen and cancelled won't un-cancel, so
     *                                there's nothing left to learn.
     *   - pulled within the window → no. Fresh enough; leave the box office alone.
     *   - pulled longer ago        → yes.
     *
     * The window is per-show — see staleAfterMinutes(): shows close to selling out go stale
     * faster than ones with plenty of seats.
     *
     * @param  array<string, array<string, mixed>>  $store
     */
    private function isDue(string $eventId, array $store, ?string $holdoverEventId = null): bool
    {
        $record = $store[$eventId] ?? null;

        if (! $record || empty($record['pulled_at'])) {
            return true;
        }

        $performances = $record['performances'] ?? [];

        // A pulled record with no performances at all is a wiped one — mid-festival no show
        // loses its entire run, so don't let it sit out a freshness window looking "fresh":
        // it's due until a pull brings its showtimes back.
        if ($performances === []) {
            return true;
        }

        // A holdover event we hold no performances for means the run just grew showtimes the
        // record doesn't know about — due regardless of freshness, and checked before the
        // terminal rule below, since a fully-played festival run would otherwise be final
        // and the holdover would never be scraped at all.
        if ($holdoverEventId && $holdoverEventId !== $eventId
            && ! collect($performances)->contains(fn (array $p) => $p['holdover'] ?? false)) {
            return true;
        }

        $terminal = [TicketAvailability::SOLD_OUT, TicketAvailability::CANCELLED];

        // A played performance is as final as a sold-out one — nothing about it can change,
        // so a run that's entirely sold out, cancelled, or over is never touched again.
        if (collect($performances)->every(
            fn (array $p) => in_array($p['status'] ?? null, $terminal, true) || TicketAvailability::isPast($p)
        )) {
            return false;
        }

        return Carbon::parse($record['pulled_at'])->lt(now()->subMinutes($this->staleAfterMinutes($performances)));
    }

    /**
     * How long this show's last pull stays fresh, from the fraction of seats still free
     * across the run (cancelled showtimes excluded, mirroring ShowAvailability's math).
     * No seat data yet → the default window. A holdover showtime still on sale overrides
     * every tier — those handful of performances sell on days of notice.
     *
     * @param  array<int, array<string, mixed>>  $performances
     */
    private function staleAfterMinutes(array $performances): int
    {
        $buyable = collect($performances)
            ->reject(fn (array $p) => in_array($p['status'] ?? null, [TicketAvailability::CANCELLED, TicketAvailability::SOLD_OUT], true))
            // Played performances excluded too: only seats someone can still buy should
            // decide how urgently the remainder of the run gets re-checked.
            ->reject(fn (array $p) => TicketAvailability::isPast($p));

        if ($buyable->contains(fn (array $p) => $p['holdover'] ?? false)) {
            return self::STALE_MINUTES_HOLDOVER;
        }

        $withSeats = collect($performances)
            ->reject(fn (array $p) => ($p['status'] ?? null) === TicketAvailability::CANCELLED)
            ->reject(fn (array $p) => TicketAvailability::isPast($p))
            ->filter(fn (array $p) => ($p['seats_total'] ?? null) !== null);

        $offered = $withSeats->sum('seats_total');

        if ($offered <= 0) {
            return self::STALE_MINUTES_DEFAULT;
        }

        $remaining = $withSeats->sum('seats_free') / $offered;

        return match (true) {
            $remaining < 0.25 => self::STALE_MINUTES_SCARCE,
            $remaining < 0.50 => self::STALE_MINUTES_SELLING,
            default => self::STALE_MINUTES_DEFAULT,
        };
    }

    /**
     * The stored records for this year, keyed by event id. A store from a different year is
     * ignored — a new festival starts empty.
     *
     * @return array<string, array<string, mixed>>
     */
    private function load(string $year): array
    {
        if (! Storage::exists(self::PATH)) {
            return [];
        }

        $previous = json_decode(Storage::get(self::PATH), true);

        if (($previous['year'] ?? null) !== $year) {
            return [];
        }

        return collect($previous['shows'] ?? [])
            ->keyBy(fn (array $row) => $row['event_id'] ?? TicketPage::eventId($row['ticket_link'] ?? ''))
            ->all();
    }

    /**
     * Persist the store as the lineup's shows in title order. Only shows still in the lineup
     * are written, so a dropped event falls out of the file. `generated_at` is the most
     * recent pull across all shows — what the page shows as "Last checked".
     *
     * @param  \Illuminate\Support\Collection<string, EntryContract>  $shows
     * @param  array<string, array<string, mixed>>  $store
     */
    private function write(string $year, $shows, array $store): void
    {
        $rows = $shows->keys()
            ->map(fn (string $eventId) => $store[$eventId] ?? null)
            ->filter()
            ->sortBy('title')
            ->values()
            ->all();

        $generatedAt = collect($rows)->pluck('pulled_at')->filter()->max();

        Storage::put(self::PATH, json_encode([
            'generated_at' => $generatedAt,
            'year' => $year,
            'shows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * How many lineup shows currently have pulled data.
     *
     * @param  \Illuminate\Support\Collection<string, EntryContract>  $shows
     * @param  array<string, array<string, mixed>>  $store
     */
    private function covered($shows, array $store): int
    {
        return $shows->keys()->filter(fn (string $id) => ! empty($store[$id]['pulled_at']))->count();
    }
}
