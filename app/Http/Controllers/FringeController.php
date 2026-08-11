<?php

namespace App\Http\Controllers;


use App\Console\Commands\FringeSoldOutReport;
use App\Fringe\AgendaCalendar;
use App\Fringe\FestivalUrls;
use App\Fringe\ReviewRating;
use App\Fringe\Reviews;
use App\Fringe\ShowAvailability;
use App\Fringe\ShowScraper;
use App\Fringe\TicketAvailability;
use App\Fringe\TicketPage;
use App\Fringe\TicketSiteBlocked;
use App\Schema\BreadcrumbSchema;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Intervention\Image\Facades\Image;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Fields\LabeledValue;
use Statamic\Taxonomies\LocalizedTerm;

class FringeController extends Controller
{

    /**
     * The full sold-out snapshot as JSON, exact seat counts included — the numbers
     * ShowAvailability strips for the public. Shared by unguessable key, not by login:
     * the audience is a friend with a script, not a browser session. No key configured
     * or wrong key both 404 (not 403) so the URL's existence isn't confirmable, and the
     * response is noindexed for the case where the link leaks somewhere crawlable. The
     * route stays out of the sitemap by construction (controller routes never join it).
     */
    public function availabilityExport()
    {
        $key = config('fringe.availability_share_key');

        abort_unless($key && hash_equals($key, (string) request('key')), 404);

        $snapshot = ShowAvailability::snapshot();

        abort_unless((bool) $snapshot, 404);

        $shows = collect($snapshot['shows'] ?? [])->map(function (array $row) {
            $performances = collect($row['performances'] ?? [])->map(function (array $performance) {
                $total = $performance['seats_total'] ?? null;
                $free = $performance['seats_free'] ?? null;

                return [
                    'id' => $performance['id'] ?? null,
                    // Stored naive-local; stamp the festival's zone so consumers don't guess.
                    'datetime' => Carbon::parse($performance['datetime'], 'America/Edmonton')->toIso8601String(),
                    'status' => $performance['status'] ?? null,
                    'seats_total' => $total,
                    'seats_free' => $free,
                    'pct_sold' => $total > 0 && $free !== null ? (int) round(($total - $free) / $total * 100) : null,
                ];
            })->values();

            $withSeats = $performances->filter(fn (array $p) => $p['seats_total'] !== null);
            $offered = $withSeats->sum('seats_total');
            $free = $withSeats->sum('seats_free');

            return [
                'title' => $row['title'] ?? null,
                'event_id' => $row['event_id'] ?? null,
                'venue' => $row['venue'] ?? null,
                'ticket_link' => $row['ticket_link'] ?? null,
                'review_url' => $row['review_url'] ?? null,
                'checked_at' => $row['pulled_at'] ?? null,
                'capacity' => $withSeats->max('seats_total') ?: null,
                'seats_offered' => $offered ?: null,
                'seats_free' => $offered ? $free : null,
                'pct_sold' => $offered > 0 ? (int) round(($offered - $free) / $offered * 100) : null,
                'performances' => $performances->all(),
            ];
        })->values();

        return response()->json([
            'festival' => $snapshot['year'] ?? null,
            'generated_at' => $snapshot['generated_at'] ?? null,
            'note' => 'Unofficial data, scraped from tickets.fringetheatre.ca. Per-show freshness is checked_at.',
            'show_count' => $shows->count(),
            'shows' => $shows,
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function generateSocialImage(string $entry)
    {
        $entry = EntryFacade::find($entry);

        Image::make(app_path("assets/{$entry->poster->path}"));

        dd($entry);
    }

    /**
     * The stable, year-agnostic reviews URL, and the canonical home of the current
     * festival's reviews.
     *
     * This used to 302 to /fringe/{year}/reviews. It serves the content directly now
     * because the redirect meant the one URL that never changes accumulated no ranking
     * signal: Search Console showed the head terms ("edmonton fringe reviews",
     * "fringe reviews", "edmonton international fringe festival reviews") all landing on
     * whichever year page Google happened to pick, stuck at positions 11-14 and clicking
     * through at well under 1%. Every August that page became a year staler against a
     * query with no year in it. Pointing the head terms at one URL that is always current
     * is the fix.
     */
    public function currentYear()
    {
        $slug = FestivalUrls::currentSlug();

        $festival = TermFacade::find("fringe_festival::{$slug}");

        abort_if(! $festival, 404);

        return $this->yearReviews($festival, $slug, "fringe-{$slug}");
    }

    /**
     * Every festival year's reviews page. Adding a year is now a matter of creating the
     * fringe_festival term; no route or controller change needed.
     *
     * While a year is the current festival its reviews live only at /fringe/reviews and
     * this URL redirects there, so the content exists at exactly one address. Redirecting
     * rather than serving a copy that points its canonical elsewhere is deliberate: a
     * redirect is a directive Google obeys, a canonical is a hint it can overrule.
     *
     * 302, not 301: the moment the next year's term exists this URL stops redirecting and
     * starts serving its own archive, and a cached permanent redirect would outlive that.
     */
    public function year(string $year)
    {
        $festival = TermFacade::find("fringe_festival::{$year}");

        abort_if(! $festival, 404);

        if (FestivalUrls::isCurrent($year)) {
            return redirect(FestivalUrls::EVERGREEN, 302);
        }

        return $this->yearReviews($festival, $year, "fringe-{$year}");
    }

    /**
     * A review's festival year, read straight off the entry.
     *
     * The augmented `$entry->festival->slug` resolves a taxonomy term to get at the same
     * string, which is fine once and expensive across a collection that now holds every show
     * at the festival. Stored as a single-value taxonomy field, so it can arrive as either a
     * string or a one-element array depending on how the entry was written.
     */
    private static function festivalSlugOf(Entry $entry): ?string
    {
        $slug = $entry->value('festival');

        return is_array($slug) ? ($slug[0] ?? null) : $slug;
    }

    /**
     * For a returning show, the entry of the review from the year I originally saw it.
     */
    private function originalReview(Entry $entry): ?\Statamic\Contracts\Entries\Entry
    {
        $id = $entry->value('original_review');

        if (is_array($id)) {
            $id = $id[0] ?? null;
        }

        return $id ? EntryFacade::find($id) : null;
    }

    /**
     * The sold-out report: every show in the current lineup, its showtimes, and how many
     * seats each has left — sorted so the shows selling out land at the top.
     *
     * Reads the snapshot `php artisan fringe:sold-out-report` writes rather than hitting
     * the ticket site live; assembling the numbers takes ~2000 upstream requests. The
     * "Last checked" line is the snapshot's timestamp, so a stale page says so itself.
     */
    /**
     * The /fringe/agenda page: what's booked on Troy's personal Fringe Google Calendar this
     * festival, as a day-by-day agenda, with each show linked to its review when one is
     * published. Data comes from the calendar's public iCal feed via AgendaCalendar (cached
     * briefly); box-office volunteering shifts stay on the list but are labelled as shifts,
     * not shows.
     *
     * Matching an event to a review tries the exact route first — events created from the
     * ticket site's "add to calendar" carry the show's ticket link, whose event id is the
     * same one reviews store — then falls back to a normalized title comparison, since
     * hand-made calendar entries get titles like "Bat Boy: the musical" against the review's
     * "Bat Boy: The Musical".
     */
    public function agenda()
    {
        $year = FestivalUrls::currentSlug();

        $reviews = Reviews::published()
            ->filter(fn (EntryContract $entry) => (string) $entry->value('festival') === $year);

        $byEventId = $reviews->mapWithKeys(
            fn (EntryContract $entry) => ($id = TicketPage::eventId($entry->value('ticket_link'))) ? [$id => $entry] : []
        );
        $byTitle = $reviews->keyBy(fn (EntryContract $entry) => $this->normalizeTitle((string) $entry->value('title')));

        $events = AgendaCalendar::events()
            ->filter(fn (array $event) => (string) $event['starts']->year === $year)
            ->map(function (array $event) use ($byEventId, $byTitle) {
                $review = null;

                if (! $event['volunteering']) {
                    $title = $this->normalizeTitle($event['summary']);
                    // Containment both ways covers "Merkin Sisters" against "The Merkin
                    // Sisters"; the length guard keeps a stubby calendar title from
                    // swallowing half the lineup.
                    $review = $byEventId[$event['event_id']]
                        ?? $byTitle[$title]
                        ?? (strlen($title) >= 8
                            ? $byTitle->first(fn (EntryContract $entry, string $key) => str_contains($key, $title) || str_contains($title, $key))
                            : null);
                }

                return [
                    'title' => $review?->value('title') ?? $event['summary'],
                    'time' => $event['starts']->format('g:i A'),
                    'end_time' => $event['ends']?->format('g:i A'),
                    'venue' => $event['venue'],
                    'volunteering' => $event['volunteering'],
                    'past' => $event['starts']->isPast(),
                    'review_url' => $review?->url(),
                    'rating' => $review ? ReviewRating::for($review) : null,
                    'starts' => $event['starts'],
                ];
            });

        $days = $events
            ->groupBy(fn (array $event) => $event['starts']->toDateString())
            ->map(fn (Collection $dayEvents) => [
                'label' => $dayEvents->first()['starts']->format('l, F j'),
                'events' => $dayEvents->map(fn (array $event) => collect($event)->except('starts')->all())->values()->all(),
            ])
            ->values()
            ->all();

        $title = "Troy's Edmonton Fringe {$year} agenda";

        return (new \Statamic\View\View)
            ->template('fringe/agenda')
            ->layout('layout')
            ->with([
                'days' => $days,
                'show_count' => $events->where('volunteering', false)->count(),
                'volunteer_count' => $events->where('volunteering', true)->count(),
                // "Reviewed" means a verdict on *this* staging: stars given this year, not a
                // rating inherited from an earlier run of a returning show (rows still show
                // inherited stars — they just don't claim the show has been reviewed yet).
                'reviewed_count' => $events->filter(
                    fn (array $event) => $event['rating'] && ! $event['rating']['inherited']
                )->count(),
                'calendar_url' => AgendaCalendar::SHARE_URL,
                // A personal schedule, not something search results need.
                'noindex' => true,
                'year' => $year,
                'title' => $title,
                'og_title' => $title,
                'og_description' => "The shows booked on my calendar for this year's Edmonton Fringe, day by day, linked to reviews as they land.",
                'canonical_url' => FestivalUrls::absolute('/fringe/agenda'),
                'breadcrumbs' => BreadcrumbSchema::trailFor([
                    ['name' => 'Agenda', 'path' => '/fringe/agenda'],
                ]),
                'breadcrumb_schema' => BreadcrumbSchema::build(BreadcrumbSchema::trailFor([
                    ['name' => 'Agenda', 'path' => '/fringe/agenda'],
                ])),
            ]);
    }

    /**
     * Case, punctuation, and spacing collapsed so calendar-event titles and review titles
     * can meet in the middle.
     */
    private function normalizeTitle(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)));
    }

    public function soldOut()
    {
        $report = Storage::exists(FringeSoldOutReport::PATH)
            ? json_decode(Storage::get(FringeSoldOutReport::PATH), true)
            : [];

        $year = $report['year'] ?? FestivalUrls::currentSlug();

        // Exact seats-left/percent-sold are the Fringe's box-office numbers; we get to look at
        // them, the public doesn't. A logged-in Statamic user sees the real counts; everyone
        // else gets only the available / low / sold-out bucket, and the raw numbers never
        // enter the rendered HTML at all — they're dropped here, not merely hidden with CSS.
        $revealNumbers = auth()->check();

        // Availability we've actually scraped, keyed by event id.
        $store = collect($report['shows'] ?? [])
            ->keyBy(fn (array $row) => $row['event_id'] ?? TicketPage::eventId($row['ticket_link'] ?? ''));

        // Every show in the lineup gets a row — we have an entry for all of them. One the
        // priority queue hasn't reached yet just shows "no data yet" rather than dropping off
        // the list, so the page is the whole festival from day one, filling in as it scrapes.
        $venues = [];

        $shows = Reviews::all()
            ->filter(fn (EntryContract $entry) => (string) $entry->value('festival') === $year)
            ->filter(fn (EntryContract $entry) => TicketPage::eventId($entry->value('ticket_link')))
            ->map(function (EntryContract $entry) use ($store, $revealNumbers, &$venues) {
                $venueId = $entry->value('venue');
                $venues[$venueId] ??= $venueId ? EntryFacade::find($venueId)?->value('title') : null;

                $eventId = TicketPage::eventId($entry->value('ticket_link'));

                $base = [
                    'title' => $entry->value('title'),
                    'ticket_link' => $entry->value('ticket_link'),
                    'review_url' => $entry->published() ? $entry->url() : null,
                    'venue' => $venues[$venueId],
                    // For the admin per-show refresh button — the id the endpoint scrapes.
                    'event_id' => $eventId,
                    'duration_minutes' => (int) $entry->value('duration') ?: null,
                ];

                $record = $store->get($eventId);

                // Never scraped: a placeholder row that sorts to the bottom (sort_pct -1).
                if (! $record) {
                    return [
                        ...$base,
                        'has_data' => false,
                        'performances' => [],
                        'sold_out_count' => 0,
                        'low_count' => 0,
                        'reduced_count' => 0,
                        'performance_count' => 0,
                        'capacity' => null,
                        'sold_pct' => null,
                        'sort_pct' => -1,
                    ];
                }

                // Shaping — the tiers, the two-column split, the auth-gated seat figures —
                // lives in App\Fringe\ShowAvailability so the review-page card renders exactly
                // the same data from exactly the same code.
                return [
                    ...$base,
                    'has_data' => true,
                    ...ShowAvailability::shape($record, $revealNumbers),
                ];
            })
            // Admins rank by exact percentage sold. The public rank is deliberately coarser
            // (see publicSortKey): shows with sold-out performances on top, then 15%-wide
            // bands of percentage sold, alphabetical within each — so the page never publishes
            // an exact most-sold ordering of every show.
            ->sortBy($revealNumbers
                ? [['sort_pct', 'desc'], ['sold_out_count', 'desc'], ['title', 'asc']]
                : fn (array $row) => $this->publicSortKey($row))
            ->values();

        $title = "Edmonton Fringe {$year} Ticket Availability by Show";

        return (new \Statamic\View\View)
            ->template('fringe/sold-out')
            ->layout('layout')
            ->with([
                'shows' => $shows->all(),
                'show_count' => $shows->count(),
                'checked_count' => $shows->where('has_data', true)->count(),
                'performance_count' => $shows->sum('performance_count'),
                'sold_out_count' => $shows->sum('sold_out_count'),
                'reveal_numbers' => $revealNumbers,
                // Our own reading of the festival's box office — keep it out of search results.
                'noindex' => true,
                'year' => $year,
                'title' => $title,
                'og_title' => $title,
                'og_description' => 'Which Edmonton Fringe shows are selling out, showing by showing — seats remaining for every performance in the festival.',
                // A screenshot of this page (its public view), generated by fringe:og-availability.
                'og_image' => ['url' => FestivalUrls::absolute('/assets/'.\App\Console\Commands\GenerateAvailabilityOgCard::PATH)],
                'canonical_url' => FestivalUrls::absolute('/fringe/ticket-availability'),
                'breadcrumbs' => BreadcrumbSchema::trailFor([
                    ['name' => 'Ticket Availability', 'path' => '/fringe/ticket-availability'],
                ]),
                'breadcrumb_schema' => BreadcrumbSchema::build(BreadcrumbSchema::trailFor([
                    ['name' => 'Ticket Availability', 'path' => '/fringe/ticket-availability'],
                ])),
            ]);
    }

    /**
     * The public ordering key for one show on the ticket-availability page — deliberately
     * coarse, so the page never publishes an exact most-sold ranking of every show:
     *
     *   1. shows with any sold-out performance, by how many (most first);
     *   2. then 15%-wide bands of percentage sold — 85%+, 70%+, 55%+, … down to the rest;
     *   3. alphabetical within every group; not-yet-scraped shows dead last.
     *
     * `sort_pct` is the real percentage (computed server-side, never emitted publicly), so the
     * bands are honest without exposing the number. Returned as one ascending composite string
     * to keep it a single sort key (Collection::sortBy with an array of closures silently
     * sorts by only the last).
     *
     * @param  array<string, mixed>  $row
     */
    private function publicSortKey(array $row): string
    {
        $bucket = match (true) {
            ! $row['has_data'] => 8,
            $row['sold_out_count'] > 0 => 0,
            $row['sort_pct'] >= 85 => 1,
            $row['sort_pct'] >= 70 => 2,
            $row['sort_pct'] >= 55 => 3,
            $row['sort_pct'] >= 40 => 4,
            $row['sort_pct'] >= 25 => 5,
            $row['sort_pct'] >= 10 => 6,
            default => 7,
        };

        // bucket, then most-sold-out first within the top bucket (complement so a higher count
        // sorts earlier under an ascending sort), then title.
        return sprintf('%d|%03d|%s', $bucket, 999 - $row['sold_out_count'], mb_strtolower((string) $row['title']));
    }

    /**
     * On-demand refresh of one show's availability, for the admin refresh button on
     * /fringe/ticket-availability — step one of three. The refresh is split per performance so no
     * single request has to survive a whole show's scrape (a 9-showtime show is ~19 ticket-site
     * calls with pauses, which blows PHP's execution limit): the front-end calls start once,
     * then performance once per pending showtime, then finish.
     *
     * Start makes the one performances-list call, merges it against the stored records (the
     * skip rules — sold-out carried forward, cancelled final — live in ShowScraper::plan so
     * the bulk command and this path agree), saves the merged skeleton, and returns the ids
     * still needing a scrape. The freshness stamp is finish's job — a half-done refresh must
     * not claim to be current.
     *
     * Admin only, like every step: the responses feed a page that shows exact seat figures.
     */
    public function refreshStart(\Illuminate\Http\Request $request)
    {
        abort_unless(auth()->check(), 403);

        $event = (string) $request->input('event');
        abort_unless(preg_match('/^\d+:\d+$/', $event), 422);

        [$report, $index] = $this->snapshotShow($event);

        abort_unless($index !== false, 404);

        // The ticket site's WAF can decide it doesn't like our IP and serve a challenge page
        // instead of JSON. That must not masquerade as a successful refresh — tell the
        // front-end explicitly so it can say so instead of swapping in the same stale data.
        try {
            $plan = ShowScraper::plan($event, FestivalUrls::currentSlug(), $report['shows'][$index]['performances'] ?? []);
        } catch (TicketSiteBlocked) {
            return response()->json(['blocked' => true], 503);
        }

        $report['shows'][$index]['performances'] = $plan['records'];
        $this->writeSnapshot($report);

        return response()->json(['pending' => $plan['pending']]);
    }

    /**
     * Step two: scrape one performance of the show (its status and seat counts, two
     * ticket-site calls) and merge just that record back into the snapshot. Called once per
     * id that refreshStart returned as pending; each write lands immediately, so a run that
     * dies partway keeps the showtimes it did manage.
     */
    public function refreshPerformance(\Illuminate\Http\Request $request)
    {
        abort_unless(auth()->check(), 403);

        $event = (string) $request->input('event');
        $performance = (string) $request->input('performance');
        abort_unless(preg_match('/^\d+:\d+$/', $event), 422);
        abort_unless(preg_match('/^\d+:\d+$/', $performance), 422);

        [$report, $index] = $this->snapshotShow($event);

        abort_unless($index !== false, 404);

        $records = collect($report['shows'][$index]['performances'] ?? []);
        $at = $records->search(fn (array $record) => ($record['id'] ?? null) === $performance);

        abort_unless($at !== false, 404);

        try {
            $records[$at] = ShowScraper::scrape($records[$at]);
        } catch (TicketSiteBlocked) {
            return response()->json(['blocked' => true], 503);
        }

        $report['shows'][$index]['performances'] = $records->all();
        $this->writeSnapshot($report);

        return response()->json(['ok' => true]);
    }

    /**
     * Step three: stamp the show fresh and return the re-rendered showtimes and header tags
     * plus the new "checked" time, which the page swaps in without a reload. No ticket-site
     * calls — everything was scraped by the steps before it.
     */
    public function refreshFinish(\Illuminate\Http\Request $request)
    {
        abort_unless(auth()->check(), 403);

        $event = (string) $request->input('event');
        abort_unless(preg_match('/^\d+:\d+$/', $event), 422);

        [$report, $index] = $this->snapshotShow($event);

        abort_unless($index !== false, 404);

        $report['shows'][$index]['pulled_at'] = now()->toIso8601String();
        $report['generated_at'] = collect($report['shows'])->pluck('pulled_at')->filter()->max();
        $this->writeSnapshot($report);

        // Push the snapshot to production, same post-hook as the scheduled refresh in
        // routes/console.php — a manual refresh is Troy noticing a show needs fresh numbers,
        // which is exactly the data production should have. Local only for the same reason
        // as the scheduler: production is the push target, not the pusher. Synchronous on
        // purpose (a second or two on top of a multi-request scrape), so a failure is known
        // before the UI says "done"; the script no-ops when the file hasn't changed.
        if (app()->environment('local')) {
            $sync = \Illuminate\Support\Facades\Process::path(base_path())->run('bin/sync-sold-out-report.sh');

            if (! $sync->successful()) {
                \Illuminate\Support\Facades\Log::warning('sold-out-report sync to production failed after manual refresh', [
                    'event' => $event,
                    'output' => trim($sync->errorOutput() ?: $sync->output()),
                ]);
            }
        }

        $data = ShowAvailability::forEventId($event, true);

        abort_unless($data, 404);

        $data['reveal_numbers'] = true;

        // The tags partial being re-rendered shows the running time too, and that lives on
        // the entry, not in the snapshot — without this a refresh would swap the tag away.
        $show = Reviews::all()->first(
            fn (EntryContract $entry) => TicketPage::eventId($entry->value('ticket_link')) === $event
        );
        $data['duration_minutes'] = (int) $show?->value('duration') ?: null;

        return response()->json([
            'checked' => $data['checked'],
            'tags_html' => (new \Statamic\View\View)->template('fringe/_availability-tags')->with($data)->render(),
            'showtimes_html' => (new \Statamic\View\View)->template('fringe/_availability-showtimes')->with($data)->render(),
        ]);
    }

    /**
     * The decoded sold-out snapshot and the index of one show's row in it (false when the
     * snapshot or the show is missing).
     *
     * @return array{0: array<string, mixed>, 1: int|false}
     */
    private function snapshotShow(string $event): array
    {
        $report = ShowAvailability::snapshot() ?? [];

        $index = collect($report['shows'] ?? [])->search(
            fn (array $row) => ($row['event_id'] ?? TicketPage::eventId($row['ticket_link'] ?? '')) === $event
        );

        return [$report, $index];
    }

    private function writeSnapshot(array $report): void
    {
        Storage::put(FringeSoldOutReport::PATH, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The sales leaderboard — every scraped show ranked by how it's selling. Admin only: it's
     * nothing but the Fringe's box-office numbers, so it must never be reachable logged out.
     *
     * Rows are rendered in the default "most tickets sold" order with server-side ranks so the
     * table is sensible without JavaScript; the leaderboard Alpine component re-sorts and
     * renumbers in place when the selector changes.
     */
    public function salesLeaderboard()
    {
        abort_unless(auth()->check(), 403);

        $snapshot = ShowAvailability::snapshot();
        $year = $snapshot['year'] ?? FestivalUrls::currentSlug();

        $rows = collect($snapshot['shows'] ?? [])
            ->filter(fn (array $show) => ! empty($show['performances']))
            ->map(function (array $show) {
                $shaped = ShowAvailability::shape($show, true);

                return [
                    'title' => $show['title'],
                    'review_url' => $show['review_url'] ?? null,
                    'sold_count' => $shaped['sold_count'] ?? 0,
                    'sold_out_count' => $shaped['sold_out_count'],
                    // A show with no seating-plan data has no percentage; it sorts last on the
                    // percentage view (sort value -1) and shows a dash.
                    'sold_pct' => $shaped['sold_pct'],
                    'sold_pct_sort' => $shaped['sold_pct'] ?? -1,
                    'sold_pct_display' => $shaped['sold_pct'] === null ? '—' : $shaped['sold_pct'].'%',
                ];
            })
            ->sortByDesc('sold_count')
            ->values()
            ->map(fn (array $row, int $i) => [...$row, 'rank' => $i + 1])
            ->all();

        $title = "Sales Leaderboard — {$year} Edmonton Fringe";

        return (new \Statamic\View\View)
            ->template('fringe/sales-leaderboard')
            ->layout('layout')
            ->with([
                'rows' => $rows,
                'row_count' => count($rows),
                // Already 403s anyone not logged in; noindex too, in case that ever changes.
                'noindex' => true,
                'year' => $year,
                'title' => $title,
                'og_title' => $title,
                'canonical_url' => FestivalUrls::absolute('/fringe/ticket-availability/leaderboard'),
                'breadcrumbs' => BreadcrumbSchema::trailFor([
                    ['name' => 'Ticket Availability', 'path' => '/fringe/ticket-availability'],
                    ['name' => 'Sales Leaderboard', 'path' => '/fringe/ticket-availability/leaderboard'],
                ]),
                'breadcrumb_schema' => BreadcrumbSchema::build(BreadcrumbSchema::trailFor([
                    ['name' => 'Ticket Availability', 'path' => '/fringe/ticket-availability'],
                    ['name' => 'Sales Leaderboard', 'path' => '/fringe/ticket-availability/leaderboard'],
                ])),
            ]);
    }

    /**
     * Whether the show carries the improv category, which is what earns it the "Improv*"
     * badge in fringe/_review-tag when it has no rating of its own.
     */
    private function isImprov(Entry $entry): bool
    {
        return $entry->categories?->contains(fn (LocalizedTerm $term) => $term->slug === 'improv') ?? false;
    }

    /**
     * A show-level availability tier for every scraped show, keyed by event id, for the
     * reviews-index status light. Reads the same sold-out snapshot the report page serves.
     *
     * @return array<string, array{tier: string, label: string}>
     */
    private function availabilityByEvent(): array
    {
        if (! Storage::exists(FringeSoldOutReport::PATH)) {
            return [];
        }

        $report = json_decode(Storage::get(FringeSoldOutReport::PATH), true);

        $out = [];

        foreach ($report['shows'] ?? [] as $show) {
            $eventId = $show['event_id'] ?? TicketPage::eventId($show['ticket_link'] ?? '');
            $tier = $eventId ? $this->showTier($show['performances'] ?? []) : null;

            if ($tier) {
                $out[$eventId] = $tier;
            }
        }

        return $out;
    }

    /**
     * Collapse a whole show's performances into one availability tier for the index light.
     * Sold out only when the entire run is gone; otherwise banded on how sold the run is
     * overall, with the ticket site's own "low" as a fallback when we lack seat counts.
     *
     * @param  array<int, array<string, mixed>>  $performances
     * @return array{tier: string, label: string}|null  null when there's nothing to show
     */
    private function showTier(array $performances): ?array
    {
        if ($performances === []) {
            return null;
        }

        $perfs = collect($performances);

        // A cancelled showtime isn't a state of the run's availability, so judge the tier on
        // the showtimes that are actually happening. If none are, the whole run is cancelled.
        $live = $perfs->reject(fn (array $p) => ($p['status'] ?? null) === TicketAvailability::CANCELLED);

        if ($live->isEmpty()) {
            return ['tier' => 'cancelled', 'label' => 'Cancelled'];
        }

        $allSoldOut = $live->every(fn (array $p) => ($p['status'] ?? null) === TicketAvailability::SOLD_OUT);

        $withSeats = $live->filter(fn (array $p) => ($p['seats_total'] ?? null) !== null);
        $offered = $withSeats->sum('seats_total');
        $pctSold = $offered > 0 ? ($offered - $withSeats->sum('seats_free')) / $offered * 100 : null;
        $anyLow = $live->contains(fn (array $p) => ($p['status'] ?? null) === TicketAvailability::LOW);

        $tier = match (true) {
            $allSoldOut => 'sold_out',
            $pctSold !== null && $pctSold >= 80 => 'low',
            $pctSold !== null && $pctSold >= 60 => 'reduced',
            $anyLow => 'low',
            default => 'available',
        };

        return [
            'tier' => $tier,
            'label' => ['sold_out' => 'Sold out', 'low' => 'Low', 'reduced' => 'Reduced', 'available' => 'Available'][$tier],
        ];
    }

    private function yearReviews($festival, string $festivalSlug, string $videoCategorySlug)
    {
        // Published only — see App\Fringe\Reviews. This list feeds the visible table, the
        // review count and the page's JSON-LD ItemList, so a draft leaking in would put a
        // 404 into structured data.
        $reviews = Reviews::published()
            // The raw value, not `$entry->festival->slug`. The augmented form resolves a
            // taxonomy term per entry to read a year that's already sitting in the file —
            // 30ms across the collection against 0.2ms, for an identical result.
            ->filter(function (Entry $entry) use ($festivalSlug) {
                return self::festivalSlugOf($entry) === $festivalSlug;
            })
            ->sortByDesc(function (Entry $entry) {
                // We sort by stars on a 0-50 scale. A returning show without a fresh rating
                // inherits the original review's stars.
                $stars = $entry->stars->value() ?: $this->originalReview($entry)?->stars->value();

                if ($stars) {
                    return (float) $stars * 10;
                }

                // Unrated shows slot onto the same scale. The buckets mirror the badges in
                // fringe/_review-tag, and are checked in the same order, so the list reads the
                // way the badges suggest. Watchlist and improv both sit just above a 3-star
                // show, watchlist first: a show Troy picked out unseen is a better bet than
                // "it's improv, you get what you get".
                return match (true) {
                    // Below everything, including a one-star show. `pending` means the show
                    // was imported from the ticket site and never looked at, so it carries
                    // no opinion at all and can't be ranked among ones that do. These are
                    // drafts and shouldn't reach this list — but if one is ever published by
                    // accident it sinks rather than floating above the rated shows.
                    Reviews::isPending($entry) => 0,
                    $entry->recommendation->value() === 'watchlist' => 32,
                    $this->isImprov($entry) => 31,
                    default => 35,
                };
            });

        // A show-level availability light beside each ticket link, from the sold-out scrape.
        // Current festival only — the snapshot is this year's box office, and a past year's
        // reviews are an archive whose tickets are long gone; "Available" there is nonsense.
        // Set as a supplement so the entry stays augmentable; the template reads `availability`
        // and `availability_label`. Only the bucket crosses over, never seat numbers.
        if (FestivalUrls::isCurrent($festivalSlug)) {
            $availability = $this->availabilityByEvent();

            $reviews->each(function (Entry $entry) use ($availability) {
                $eventId = TicketPage::eventId($entry->value('ticket_link'));

                if ($eventId && isset($availability[$eventId])) {
                    $entry->setSupplement('availability', $availability[$eventId]['tier']);
                    $entry->setSupplement('availability_label', $availability[$eventId]['label']);
                }
            });
        }

        // The filter bar reads raw slugs off each row, for the same reason as the videos
        // query below: `{{ categories }}` in the template would resolve a taxonomy term per
        // entry. The dropdown offers only categories this festival's shows actually use, so
        // an archive year without category data simply gets no dropdown.
        $usedCategories = $reviews
            ->flatMap(fn (Entry $entry) => (array) ($entry->value('categories') ?? []))
            ->unique()
            ->all();

        $reviews->each(function (Entry $entry) {
            $entry->setSupplement('category_slugs', implode(' ', (array) ($entry->value('categories') ?? [])));
        });

        $categoryOptions = TermFacade::query()
            ->where('taxonomy', 'fringe_show_categories')
            ->get()
            ->filter(fn ($term) => in_array($term->slug(), $usedCategories, true))
            ->map(fn ($term) => ['slug' => $term->slug(), 'title' => $term->title()])
            ->sortBy('title')
            ->values();

        // Raw category slugs rather than `$entry->category`, which resolves a taxonomy term
        // for every video on the site — 109ms against 8ms, and it was the single most
        // expensive thing on the reviews page despite having nothing to do with reviews.
        //
        // Not `whereTaxonomy()`, tempting as it is at 0.4ms: `video_category` isn't declared
        // on the videos collection, only referenced by a blueprint field, so the taxonomy
        // index is empty and the query returns **nothing for every category** — silently, and
        // for fringe-2026 it happens to agree with the correct answer because both are empty.
        // Declaring the taxonomy on the collection would make it usable; until then this is
        // the version that returns the right videos.
        $videos = EntryFacade::query()
            ->where('collection', 'videos')
            ->get()
            ->filter(function (Entry $entry) use ($videoCategorySlug) {
                return in_array($videoCategorySlug, (array) ($entry->value('category') ?? []), true);
            });

        $posts = $this->posts($festivalSlug);

        $lastUpdated = $this->lastUpdated($reviews) ?? $festival?->lastModified();

        $ratedCount = $reviews->filter(fn (Entry $entry) => $entry->stars->value() !== null)->count();

        // Each page is canonical to itself. Nothing cross-canonicalizes: the current festival
        // only ever renders at /fringe/reviews and every other year only ever at its own URL,
        // so there is no duplicate to point away from. FestivalUrls already knows which is
        // which, which is why there's no branch here.
        $canonical = FestivalUrls::absoluteReviews($festivalSlug);

        // Two rungs — the /fringe hub and this page. The show pages hang a third off the same
        // builder, so the trail reads consistently across the section.
        $breadcrumbs = \App\Schema\BreadcrumbSchema::forReviewsIndex($festivalSlug);

        // Page metadata lives on the festival term rather than a stub page entry. Those
        // entries held nothing but a title and og tags, and their filename-derived slugs
        // were what produced duplicate, controller-less copies of this page.
        //
        // The year sits in the tagline rather than a "(2025)" stamp after the name. The
        // problem was never the year itself, it was a *frozen* year: the old page kept
        // saying 2025 into 2026, and Search Console showed "edmonton fringe reviews 2025"
        // converting at 16% while the unqualified query managed 0.5% from a better
        // position. On /fringe/reviews this always renders the current festival, so it
        // matches both shapes of the query and is never stale.
        $title = $festival?->value('og_title')
            ?: "Edmonton Fringe Reviews | The best shows at the {$festivalSlug} Fringe";

        // Concrete beats vague. "best fringe shows" was sitting at position 6 with zero
        // clicks on the old description, which promised reviews without saying how many
        // or what they'd tell you.
        $description = $festival?->value('og_description') ?: ($ratedCount > 0
            ? "{$ratedCount} shows at the {$festivalSlug} Edmonton Fringe, each rated out of five. Honest takes, so you can find one worth your ticket."
            : "Every show I see at the {$festivalSlug} Edmonton Fringe, rated out of five. Honest takes, so you can find one worth your ticket.");

        return (new \Statamic\View\View)
            ->template('fringe/index')
            ->layout('layout')
            ->with([
                'reviews' => $reviews,
                'category_options' => $categoryOptions,
                'videos' => $videos,
                'posts' => $posts,
                'year' => $festivalSlug,
                // The ticket-availability page only covers the current festival, so the link
                // to it belongs on the current year's index and nowhere else.
                'is_current_festival' => FestivalUrls::isCurrent($festivalSlug),
                'tickets_available' => (bool) $festival?->value('tickets_available'),
                'review_count' => $reviews->count(),
                'rated_count' => $ratedCount,
                'last_updated_display' => $lastUpdated?->format('F j, Y'),
                'last_updated_iso' => $lastUpdated?->toIso8601String(),
                'canonical_url' => $canonical,
                // Only the current festival has a feed — see the route comment. An archive
                // year advertising one would promise updates that will never come.
                'feed_url' => FestivalUrls::isCurrent($festivalSlug) ? '/fringe/reviews/feed.xml' : null,
                'feed_title' => "Edmonton Fringe Reviews ({$festivalSlug})",
                'breadcrumbs' => $breadcrumbs,
                'breadcrumb_schema' => \App\Schema\BreadcrumbSchema::build($breadcrumbs),
                'structured_data' => $this->structuredData($title, $description, $reviews, $festivalSlug, $festival, $lastUpdated, $canonical),
                'title' => $title,
                'og_title' => $title,
                'og_description' => $description,
                // Built with the card generator (App\Og\CardRenderer), like every other
                // sharing image on the site. This page is a controller route rather than an
                // entry, so there's no CP action for it — the command that made it is:
                //
                //   php artisan og:card --out=og/fringe-reviews.png \
                //     --headline="Troy's Fringe Reviews" \
                //     --subhead="The most excellent Fringe reviews you will find. It says so right here." \
                //     --images=six-shows-to-watch-at-fringe-2026/edmontask-feed.png \
                //     --images=six-shows-to-watch-at-fringe-2026/sketchy-broads-presents-resting-bitumen-face-feed.png \
                //     --images=six-shows-to-watch-at-fringe-2026/2026-field-zoology-301-feed.png \
                //     --portrait=fringe/fringe-with-atlas-2026.jpg --portrait-focus=15%
                //
                // Show art says what the reviews are about; a face says who is doing the
                // reviewing, and this is a page that lives or dies on whether you trust the
                // reviewer. `--portrait-focus=15%` is where the square crop keeps his whole
                // face, the programme and the cat — the default centre cuts his face off and
                // leaves the cat as the subject, and past about 20% his cheek starts to clip.
                //
                // An array with a bare `url` rather than an asset, because the layout needs
                // an absolute URL here and this is the one place that knows the origin.
                'og_image' => ['url' => FestivalUrls::absolute('/assets/og/fringe-reviews.png')],
            ]);
    }

    /**
     * Fringe posts about this festival, newest first.
     *
     * Most readers arrive here from search and never see the /fringe hub, so the writing has
     * to be reachable from the page they actually land on.
     *
     * Filtered in PHP rather than queried: `festivals` is a computed field, and computed
     * fields aren't in the Stache index. The posts collection is small enough that this
     * doesn't matter, and the alternative is a second tag to keep in sync by hand.
     */
    private function posts(string $festivalSlug): Collection
    {
        return EntryFacade::query()
            ->where('collection', 'posts')
            ->where('published', true)
            ->get()
            ->filter(fn (Entry $entry) => $entry->topics?->contains(fn (LocalizedTerm $term) => $term->slug === 'fringe')
                && in_array($festivalSlug, $entry->augmentedValue('festivals')->value() ?? [], true))
            ->sortByDesc(fn (Entry $entry) => $entry->date())
            ->values();
    }

    /**
     * The most recently touched review in the list — the page's freshness date.
     */
    private function lastUpdated(Collection $reviews): ?Carbon
    {
        return $reviews
            ->map(fn (Entry $entry) => $entry->lastModified())
            ->filter()
            ->max();
    }

    /**
     * JSON-LD for the reviews index.
     *
     * The list itself is a summary page, so each item is a plain ListItem pointing
     * at the show's own page — the full Review markup (rating, author) lives there,
     * which is the structure Google documents for list-plus-detail pages.
     */
    private function structuredData(string $title, string $description, Collection $reviews, string $festivalSlug, $festival, ?Carbon $lastUpdated, string $canonical): string
    {
        $items = $reviews
            ->values()
            ->map(fn (Entry $entry, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => $entry->absoluteUrl(),
                'name' => $entry->value('title'),
            ])
            ->all();

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $title,
            'description' => $description,
            // The canonical, not request()->url(): the current year answers on two URLs and
            // the markup should name the one that owns the content.
            'url' => $canonical,
            'dateModified' => $lastUpdated?->toIso8601String(),
            'inLanguage' => 'en-CA',
            'author' => [
                '@type' => 'Person',
                'name' => 'Troy Pavlek',
                'url' => 'https://troypavlek.ca',
            ],
            // Festival is an Event subtype, so Google validates it as one and startDate is
            // required — Search Console flagged this page over it. A term with no dates
            // emits no `about` at all rather than an Event that can't validate.
            'about' => $festival?->value('starts_at') ? array_filter([
                '@type' => 'Festival',
                'name' => "Edmonton International Fringe Theatre Festival {$festivalSlug}",
                'startDate' => $festival->value('starts_at'),
                'endDate' => $festival->value('ends_at'),
                'location' => [
                    '@type' => 'Place',
                    'name' => 'Edmonton, Alberta',
                ],
            ]) : null,
            'mainEntity' => [
                '@type' => 'ItemList',
                'name' => "Edmonton Fringe {$festivalSlug} reviews",
                'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ],
        ]);

        // HEX_TAG so a show title containing "</script>" can't break out of the tag.
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT);
    }

}
