<?php

namespace App\Fringe;

/**
 * Scrape one show's performances into stored records — the per-show inner loop shared by the
 * bulk command (App\Console\Commands\FringeSoldOutReport) and the admin refresh endpoints
 * (FringeController::refreshStart / refreshPerformance), so both apply exactly the same rules.
 *
 * The work is split so a caller can spread it over several short requests: plan() makes the
 * one performances-list call and decides which showtimes actually need scraping, scrape()
 * does the two per-showtime calls for a single performance, and performances() runs the whole
 * thing in one go for the bulk command.
 *
 * Every call still goes one request at a time through TicketAvailability with its pause; this
 * only orchestrates. Throws TicketSiteBlocked if the ticket site starts serving its WAF
 * challenge mid-scrape — the caller decides how to back off.
 */
class ShowScraper
{
    /**
     * The show's fresh performance list merged against its last-known records, plus which
     * performance ids still need scraping. One request to the ticket site.
     *
     * `$prior` is keyed on to carry sold-out showtimes forward untouched (they won't reopen)
     * and skip their two per-showtime queries; cancelled showtimes are final immediately (the
     * list itself is the only honest signal — see TicketAvailability). Everything else keeps
     * its prior numbers (or a blank placeholder) so the record still renders while it waits
     * its turn in `pending`, and scrape() replaces it.
     *
     * @param  array<int, array<string, mixed>>  $prior
     * @return array{records: array<int, array<string, mixed>>, pending: array<int, string>}
     */
    public static function plan(string $eventId, string $year, array $prior = []): array
    {
        $known = collect($prior)->keyBy('id');
        $records = [];
        $pending = [];

        foreach (TicketAvailability::performances($eventId, $year) as $performance) {
            if ($performance['cancelled']) {
                $records[] = [
                    ...$performance,
                    'status' => TicketAvailability::CANCELLED,
                    'seats_total' => null,
                    'seats_free' => null,
                ];

                continue;
            }

            $priorRecord = $known->get($performance['id']);

            if ($priorRecord && ($priorRecord['status'] ?? null) === TicketAvailability::SOLD_OUT) {
                $records[] = $priorRecord;

                continue;
            }

            // A performance that has already played is final: keep whatever we knew about it
            // (a pre-curtain sold-out is the artist's cred and must survive), and never query
            // one we missed — the ticket site reports a closed sale as "no-availability",
            // which would fabricate a sold-out after the fact.
            if (TicketAvailability::isPast($performance)) {
                $records[] = $priorRecord ?? [
                    ...$performance,
                    'status' => null,
                    'seats_total' => null,
                    'seats_free' => null,
                ];

                continue;
            }

            $records[] = $priorRecord ?? [
                ...$performance,
                'status' => null,
                'seats_total' => null,
                'seats_free' => null,
            ];
            $pending[] = $performance['id'];
        }

        return ['records' => $records, 'pending' => $pending];
    }

    /**
     * Re-scrape one performance record in place: its status and seat counts, two requests.
     * Cancelled records pass through untouched — the availability endpoint would only
     * mislabel them sold out.
     *
     * @param  array<string, mixed>  $performance
     * @return array<string, mixed>
     */
    public static function scrape(array $performance): array
    {
        if (($performance['status'] ?? null) === TicketAvailability::CANCELLED) {
            return $performance;
        }

        $status = TicketAvailability::status($performance['id']);
        $seats = TicketAvailability::seats($performance['id']);

        // A performance whose status call failed outright would read as sold out, which
        // overstates. Fall back to the seat count when we have one.
        $status ??= ($seats['free'] ?? 0) > 0
            ? TicketAvailability::AVAILABLE
            : TicketAvailability::SOLD_OUT;

        return [
            ...$performance,
            'status' => $status,
            'seats_total' => $seats['total'] ?? null,
            'seats_free' => $seats['free'] ?? null,
        ];
    }

    /**
     * The stored performance records for a show, scraped in one uninterrupted run — the bulk
     * command's path. plan() then scrape() for everything pending.
     *
     * @param  array<int, array<string, mixed>>  $prior
     * @return array<int, array<string, mixed>>
     */
    public static function performances(string $eventId, string $year, array $prior = []): array
    {
        $plan = self::plan($eventId, $year, $prior);
        $pending = array_flip($plan['pending']);

        return array_map(
            fn (array $record) => isset($pending[$record['id']]) ? self::scrape($record) : $record,
            $plan['records']
        );
    }
}
