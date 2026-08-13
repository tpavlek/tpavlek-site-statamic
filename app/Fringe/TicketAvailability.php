<?php

namespace App\Fringe;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Per-performance ticket availability, off the same WordPress admin-ajax endpoints the
 * ticket site's own calendar uses.
 *
 * Three endpoints, discovered from the site's frontend bundle:
 *
 *   get_performances_for_one_day   every performance of an event in a date range. Despite
 *                                  the name it honours any range, so one call per show
 *                                  covers the whole festival.
 *   get_perf_availability          the site's own traffic light for one performance:
 *                                  "available", "low-availability", or "no-availability"
 *                                  (the JS treats anything else as sold out too).
 *   get_seating_plan               every seat with a state: "1" free, "0" taken. The
 *                                  festival is general admission, but the plan exists
 *                                  anyway, which is what gives us seats-remaining counts.
 *
 * Deliberately single-threaded, with a pause between requests. We like the Fringe and this
 * is their box office in August; a full run is ~2000 requests and it is fine for it to take
 * a while. No concurrency here, and don't add any.
 */
class TicketAvailability
{
    public const ENDPOINT = TicketPage::HOST.'/wp-admin/admin-ajax.php';

    /**
     * A performance that has already started (a minute past curtain, and datetimes are
     * naive America/Edmonton — the app timezone). Once true, its availability can never
     * change again, so scraping it is wasted requests — and actively wrong for a showtime
     * we never scraped before it played: the ticket site reports a closed sale as
     * "no-availability", which would fabricate a sold-out after the fact.
     *
     * @param  array<string, mixed>  $performance
     */
    public static function isPast(array $performance): bool
    {
        return isset($performance['datetime'])
            && \Carbon\Carbon::parse($performance['datetime'])->addMinute()->lte(\Carbon\Carbon::now());
    }

    public const AVAILABLE = 'available';
    public const LOW = 'low-availability';
    public const SOLD_OUT = 'no-availability';
    // Not a value the availability endpoint returns — a cancelled performance still reports
    // "no-availability" there. It's read from the performances list, where a cancelled
    // showtime carries a "CANCELLED" title instead of the usual empty one.
    public const CANCELLED = 'cancelled';

    /**
     * Milliseconds to sit idle between requests, on top of however long each takes.
     */
    private const PAUSE_MS = 750;

    /**
     * Every performance of an event across the whole festival month.
     *
     * @return array<int, array{id: string, datetime: string}>
     */
    public static function performances(string $eventId, string $year): array
    {
        $response = self::request([
            'action' => 'get_performances_for_one_day',
            'selectedDateFormattedForSoap' => "{$year}-08-01 00:00:00",
            'selectedDateNextDayFormattedForSoap' => "{$year}-08-31 23:59:59",
            'eventId' => $eventId,
        ]);

        if (! $response || ! is_array($response->json())) {
            return [];
        }

        return collect($response->json())
            ->map(fn (array $performance) => [
                'id' => (string) $performance['id'],
                'datetime' => (string) $performance['datetime'],
                // Normally empty; "CANCELLED" marks a cancelled showtime. The availability
                // endpoint would call it sold out, so this is the only honest signal.
                'cancelled' => strtoupper(trim((string) ($performance['title'] ?? ''))) === 'CANCELLED',
            ])
            ->sortBy('datetime')
            ->values()
            ->all();
    }

    /**
     * The site's availability status for one performance.
     */
    public static function status(string $performanceId): ?string
    {
        $response = self::request([
            'action' => 'get_perf_availability',
            'performanceId' => $performanceId,
        ]);

        // The body is a JSON-encoded bare string: "available".
        $status = is_string($response?->json()) ? $response->json() : null;

        if ($status === null) {
            return null;
        }

        return in_array($status, [self::AVAILABLE, self::LOW], true) ? $status : self::SOLD_OUT;
    }

    /**
     * Seat counts for one performance.
     *
     * @return array{total: int, free: int}|null  null when the plan can't be read
     */
    public static function seats(string $performanceId): ?array
    {
        $response = self::request([
            'action' => 'get_seating_plan',
            'performanceId' => $performanceId,
        ]);

        $seats = $response?->json('seats');

        if (! is_array($seats) || ! $seats) {
            return null;
        }

        return [
            'total' => count($seats),
            'free' => collect($seats)->where('state', '1')->count(),
        ];
    }

    private static function request(array $payload): ?Response
    {
        usleep(self::PAUSE_MS * 1000);

        try {
            $response = Http::asForm()->timeout(30)->post(self::ENDPOINT, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }

        // The site sits behind AWS WAF. Once too many requests come from one IP it stops
        // answering the ajax endpoints and serves a "Human Verification" challenge page
        // instead — an HTML body where we expect JSON. That is not "no performances"; it's
        // "we've been throttled", and the two must never be confused, or a blocked run
        // would overwrite the snapshot with a festival that appears to have no showtimes.
        // We do not try to solve the challenge; the caller's job is to back off.
        if (str_contains($response->header('Content-Type'), 'text/html')
            || str_contains($response->body(), 'Human Verification')) {
            throw new TicketSiteBlocked;
        }

        return $response->ok() ? $response : null;
    }
}
