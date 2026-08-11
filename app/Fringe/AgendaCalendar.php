<?php

namespace App\Fringe;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Troy's personal Fringe calendar — the public iCal feed of the Google Calendar where shows
 * to see (and box-office volunteering shifts) get booked. Feeds the /fringe/agenda page.
 *
 * The feed is fetched and cached briefly; a fetch failure serves the page from whatever was
 * cached (or as empty) rather than erroring — the agenda is a nicety, not something worth a
 * 500. Parsing is deliberately minimal: Google's PUBLISH feeds are flat VEVENT lists, so
 * this unfolds continuation lines and reads the handful of properties the page needs. No
 * recurrence support — the calendar doesn't use it.
 */
class AgendaCalendar
{
    public const URL = 'https://calendar.google.com/calendar/ical/c_2fd302d4268b424089d80ebd701fff64d605a53362e3941031a146d4a93d5777%40group.calendar.google.com/public/basic.ics';

    /**
     * Where the calendar lives for humans — the "add this calendar" link.
     */
    public const SHARE_URL = 'https://calendar.google.com/calendar/u/0?cid=Y18yZmQzMDJkNDI2OGI0MjQwODlkODBlYmQ3MDFmZmY2NGQ2MDVhNTMzNjJlMzk0MTAzMWExNDZkNGE5M2Q1Nzc3QGdyb3VwLmNhbGVuZGFyLmdvb2dsZS5jb20';

    public const TIMEZONE = 'America/Edmonton';

    private const CACHE_KEY = 'fringe.agenda-ics';
    private const CACHE_SECONDS = 900;

    /**
     * Every event on the calendar (all years), sorted by start time in Edmonton local time.
     *
     * @return Collection<int, array{summary: string, starts: Carbon, ends: Carbon|null, description: string, volunteering: bool, event_id: string|null, venue: string|null}>
     */
    public static function events(): Collection
    {
        $ics = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => self::fetch());

        if (! $ics) {
            return collect();
        }

        // Long lines are folded onto continuations beginning with whitespace (RFC 5545).
        $unfolded = preg_replace("/\r?\n[ \t]/", '', $ics);

        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $unfolded, $matches);

        return collect($matches[1])
            ->map(fn (string $block) => self::event($block))
            ->filter()
            ->sortBy('starts')
            ->values();
    }

    private static function fetch(): ?string
    {
        try {
            $response = Http::timeout(15)->get(self::URL);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }

        return $response->ok() ? $response->body() : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function event(string $block): ?array
    {
        if (self::property($block, 'STATUS')[1] === 'CANCELLED') {
            return null;
        }

        [$startParams, $startValue] = self::property($block, 'DTSTART');
        $starts = $startValue ? self::when($startParams, $startValue) : null;

        if (! $starts) {
            return null;
        }

        [$endParams, $endValue] = self::property($block, 'DTEND');
        $summary = self::unescape(self::property($block, 'SUMMARY')[1] ?? '') ?: '(untitled)';
        $description = self::unescape(self::property($block, 'DESCRIPTION')[1] ?? '');

        return [
            'summary' => $summary,
            'starts' => $starts,
            'ends' => $endValue ? self::when($endParams, $endValue) : null,
            'description' => $description,
            // "BO Volunteering" is a box-office shift, not a show — the page labels it
            // instead of hunting for a review to link.
            'volunteering' => (bool) preg_match('/volunteer/i', $summary),
            // Some events carry the show's ticket link in the description — the exact same
            // event id reviews store in ticket_link, so those match with no title fuzz.
            'event_id' => TicketPage::eventId($description),
            'venue' => self::venue($description),
        ];
    }

    /**
     * A property's [params, value] from a VEVENT block, e.g. DTSTART;TZID=X:20260814T020000
     * yields [';TZID=X', '20260814T020000']. [null, null] when absent.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function property(string $block, string $name): array
    {
        return preg_match('/^'.$name.'([^:\r\n]*):(.*)$/m', $block, $m)
            ? [$m[1], trim($m[2])]
            : [null, null];
    }

    private static function when(?string $params, string $value): ?Carbon
    {
        try {
            // All-day events carry a bare date.
            if (str_contains((string) $params, 'VALUE=DATE') || strlen($value) === 8) {
                return Carbon::createFromFormat('Ymd', $value, self::TIMEZONE)->startOfDay();
            }

            if (str_ends_with($value, 'Z')) {
                return Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC')->setTimezone(self::TIMEZONE);
            }

            preg_match('/TZID=([^;:]+)/', (string) $params, $tz);

            return Carbon::createFromFormat('Ymd\THis', $value, $tz[1] ?? self::TIMEZONE)->setTimezone(self::TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The venue name, when the event was created from the ticket site's "add to calendar":
     * those descriptions are "Show Title\nVenue Name, street address…". Hand-made events
     * (a bare ticket link, or nothing) have no venue to offer.
     */
    private static function venue(string $description): ?string
    {
        $second = explode("\n", $description)[1] ?? '';
        $name = trim(explode(',', $second)[0]);

        return $name !== '' && ! str_contains($name, 'http') ? $name : null;
    }

    private static function unescape(?string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], (string) $value);
    }
}
