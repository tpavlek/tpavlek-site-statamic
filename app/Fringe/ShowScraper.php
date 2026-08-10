<?php

namespace App\Fringe;

/**
 * Scrape one show's performances into stored records — the per-show inner loop shared by the
 * bulk command (App\Console\Commands\FringeSoldOutReport) and the on-demand refresh job
 * (App\Jobs\RefreshShowAvailability), so both apply exactly the same rules.
 *
 * Every call still goes one request at a time through TicketAvailability with its pause; this
 * only orchestrates. Throws TicketSiteBlocked if the ticket site starts serving its WAF
 * challenge mid-scrape — the caller decides how to back off.
 */
class ShowScraper
{
    /**
     * The stored performance records for a show. `$prior` is the show's last-known
     * performances, keyed on to carry sold-out showtimes forward untouched (they won't
     * reopen) and skip their two per-showtime queries.
     *
     * @param  array<int, array<string, mixed>>  $prior
     * @return array<int, array<string, mixed>>
     */
    public static function performances(string $eventId, string $year, array $prior = []): array
    {
        $known = collect($prior)->keyBy('id');
        $performances = [];

        foreach (TicketAvailability::performances($eventId, $year) as $performance) {
            // Cancelled is known from the performances list itself (its "CANCELLED" title), so
            // we skip both per-showtime queries — the availability endpoint would only
            // mislabel it sold out.
            if ($performance['cancelled']) {
                $performances[] = [
                    ...$performance,
                    'status' => TicketAvailability::CANCELLED,
                    'seats_total' => null,
                    'seats_free' => null,
                ];

                continue;
            }

            $priorRecord = $known->get($performance['id']);

            if ($priorRecord && ($priorRecord['status'] ?? null) === TicketAvailability::SOLD_OUT) {
                $performances[] = $priorRecord;

                continue;
            }

            $status = TicketAvailability::status($performance['id']);
            $seats = TicketAvailability::seats($performance['id']);

            // A performance whose status call failed outright would read as sold out, which
            // overstates. Fall back to the seat count when we have one.
            $status ??= ($seats['free'] ?? 0) > 0
                ? TicketAvailability::AVAILABLE
                : TicketAvailability::SOLD_OUT;

            $performances[] = [
                ...$performance,
                'status' => $status,
                'seats_total' => $seats['total'] ?? null,
                'seats_free' => $seats['free'] ?? null,
            ];
        }

        return $performances;
    }
}
