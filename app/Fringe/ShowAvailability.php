<?php

namespace App\Fringe;

use App\Console\Commands\FringeSoldOutReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * View-ready ticket availability for one show, shaped identically wherever it's shown — the
 * /fringe/ticket-availability list and the "Performance & Availability" card on a review page. Reads the
 * snapshot FringeSoldOutReport writes; never touches the ticket site.
 *
 * The `$revealNumbers` gate is the whole privacy boundary: exact seats-left and percentages
 * are the Fringe's box-office figures and are dropped here, server-side, for anyone not
 * logged in — so a public template literally never receives them. Everyone still gets the
 * bucket (available / reduced / low / sold out / cancelled), and the show's capacity — a
 * venue's room size isn't private. Callers pass `auth()->check()`.
 */
class ShowAvailability
{
    /**
     * The shaped availability for a show by its ticket event id, or null when we have no
     * scraped data for it. `has_data` is always true on a hit, mirroring the sold-out page's
     * rows so the same partials render either place.
     *
     * @return array<string, mixed>|null
     */
    public static function forEventId(?string $eventId, bool $revealNumbers): ?array
    {
        $record = self::recordFor($eventId);

        return $record ? ['has_data' => true, ...self::shape($record, $revealNumbers)] : null;
    }

    /**
     * Shape one stored show record (its performances plus freshness) into the fields every
     * availability view reads. Does not include the show's own title/venue/links — those come
     * from the caller's context, not the snapshot.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function shape(array $record, bool $revealNumbers): array
    {
        $raw = collect($record['performances'] ?? []);

        // Every performance of a show plays the same room, so the show's capacity is one
        // number, not one per showtime — take it from whichever performances we have a
        // seating plan for. Percent sold is across the whole run: seats taken over seats
        // offered, summed over every performance with plan data.
        $withSeats = $raw->filter(fn (array $p) => ($p['seats_total'] ?? null) !== null);
        $offered = $withSeats->sum('seats_total');
        $free = $withSeats->sum('seats_free');
        $soldPct = $offered > 0 ? (int) round(($offered - $free) / $offered * 100) : null;

        $performances = $raw->map(fn (array $p) => self::shapePerformance($p, $revealNumbers));

        // Whether there's nothing left to buy: at least one performance is still to come and
        // every one of them is sold out. Judged on the raw statuses with the same time rule as
        // shapePerformance (a minute past curtain a showtime stops counting), because the
        // shaped rows keep past sold-outs as "Sold out" — artist cred, not a buying signal.
        // Cancelled showtimes count neither way, and a run that's simply over isn't "sold
        // out". Powers the hide-sold-out toggles.
        $upcoming = $raw
            ->reject(fn (array $p) => ($p['status'] ?? null) === TicketAvailability::CANCELLED)
            ->reject(fn (array $p) => Carbon::parse($p['datetime'])->addMinute()->lte(Carbon::now()));
        $runSoldOut = $upcoming->isNotEmpty()
            && $upcoming->every(fn (array $p) => ($p['status'] ?? null) === TicketAvailability::SOLD_OUT);

        // Split chronologically into two halves so the desktop layout can show them as two
        // side-by-side columns — first half left, second half right. `chunk(ceil(n/2))` yields
        // [first half],[rest].
        $columns = $performances->chunk((int) ceil(max($performances->count(), 1) / 2));

        return [
            // Each show is scraped on its own schedule (the priority queue), so its freshness
            // is a per-show fact.
            'checked' => isset($record['pulled_at']) ? Carbon::parse($record['pulled_at'])->diffForHumans() : null,
            'performances' => $performances->all(),
            'performances_left' => ($columns->get(0) ?? collect())->values()->all(),
            'performances_right' => ($columns->get(1) ?? collect())->values()->all(),
            'sold_out_count' => $performances->where('sold_out', true)->count(),
            'run_sold_out' => $runSoldOut,
            'low_count' => $performances->where('low', true)->count(),
            'reduced_count' => $performances->where('reduced', true)->count(),
            // Cancelled showtimes aren't part of "N of M performances sold out".
            'performance_count' => $performances->where('cancelled', false)->count(),
            // Capacity is the venue's published room size, not a sales figure — public.
            'capacity' => $withSeats->max('seats_total') ?: null,
            // Numeric box-office facts, shown only to a logged-in user. `sort_pct` is kept
            // separately (never emitted) so the public page can share the same most-sold-first
            // order without the number that produced it.
            'sold_pct' => $revealNumbers ? $soldPct : null,
            // Total tickets sold across the whole run so far (seats offered minus still free,
            // over every performance with plan data). Admin only.
            'sold_count' => $revealNumbers ? $offered - $free : null,
            'sort_pct' => $soldPct ?? -1,
        ];
    }

    /**
     * One showtime, tiered and (for admins) counted.
     *
     * @param  array<string, mixed>  $performance
     * @return array<string, mixed>
     */
    public static function shapePerformance(array $performance, bool $revealNumbers): array
    {
        $when = Carbon::parse($performance['datetime']);
        $total = $performance['seats_total'] ?? null;
        $free = $performance['seats_free'] ?? null;
        $pctSold = $total > 0 ? ($total - $free) / $total * 100 : null;

        $cancelled = $performance['status'] === TicketAvailability::CANCELLED;
        $soldOut = $performance['status'] === TicketAvailability::SOLD_OUT;

        // A minute past curtain (datetimes are stored naive America/Edmonton, which is also
        // the app timezone) the availability question is moot — the show happened. Sold out
        // deliberately survives this transition: a show that sold out stays "Sold out"
        // forever, because selling out is the artist's cred, not a ticketing state. Cancelled
        // survives too — "completed" would claim a show happened that didn't.
        $completed = ! $soldOut && ! $cancelled && $when->copy()->addMinute()->lte(Carbon::now());

        $low = ! $completed && $performance['status'] === TicketAvailability::LOW;
        // Our own tier between available and low: a showtime the ticket site still calls
        // available, but where 40% or fewer seats remain (at least 60% sold). Derived from the
        // seat count, so it's a bucket the public can see without the raw number. Only upgrades
        // an "available" showtime — low/sold-out already outrank it.
        $reduced = ! $soldOut && ! $low && ! $cancelled && ! $completed && $pctSold !== null && $pctSold >= 60;

        $tier = match (true) {
            $cancelled => 'cancelled',
            $soldOut => 'sold_out',
            $completed => 'completed',
            $low => 'low',
            $reduced => 'reduced',
            default => 'available',
        };

        return [
            'day' => $when->format('D M j'),
            'time' => $when->format('g:i A'),
            'status' => $performance['status'],
            'tier' => $tier,
            'tier_label' => ['cancelled' => 'Cancelled', 'sold_out' => 'Sold out', 'completed' => 'Completed', 'low' => 'Low', 'reduced' => 'Reduced availability', 'available' => 'Available'][$tier],
            'cancelled' => $cancelled,
            'sold_out' => $soldOut,
            'completed' => $completed,
            'low' => $low,
            'reduced' => $reduced,
            // Seats available and what share of the room that is — admin only.
            'seats_free' => $revealNumbers ? $free : null,
            'pct_free' => $revealNumbers && $total > 0 ? (int) round($free / $total * 100) : null,
        ];
    }

    /**
     * The whole current snapshot, or null when none has been written yet.
     *
     * @return array<string, mixed>|null
     */
    public static function snapshot(): ?array
    {
        if (! Storage::exists(FringeSoldOutReport::PATH)) {
            return null;
        }

        return json_decode(Storage::get(FringeSoldOutReport::PATH), true);
    }

    /**
     * The stored record for one event id, or null.
     *
     * @return array<string, mixed>|null
     */
    private static function recordFor(?string $eventId): ?array
    {
        $snapshot = $eventId ? self::snapshot() : null;

        if (! $snapshot) {
            return null;
        }

        $match = collect($snapshot['shows'] ?? [])
            ->first(fn (array $row) => ($row['event_id'] ?? TicketPage::eventId($row['ticket_link'] ?? '')) === $eventId);

        return is_array($match) ? $match : null;
    }
}
