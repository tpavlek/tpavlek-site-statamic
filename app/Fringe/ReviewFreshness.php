<?php

namespace App\Fringe;

use Carbon\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * When a review was written, and whether it has been touched since.
 *
 * A review page carried no visible date at all — its only freshness signal was the
 * datePublished buried in the JSON-LD, which does nothing for a reader deciding whether a
 * take on a show still running is current.
 *
 * "Updated" comes from the entry's own `updated_at` field — the unix timestamp Statamic
 * itself stamps (with `updated_by`) on every CP save. An editorial fact, not a filesystem
 * one: this used to read file mtime, which changed on every deploy or migration that touched
 * a file, whether or not a word did. Console writes (imports, backfills) never touch the
 * stamp; a CP save that shouldn't count can be un-stamped by editing `updated_at` in the
 * entry's frontmatter.
 *
 * "Updated" is deliberately hard to earn, because an update stamp that everything wears is
 * worth nothing — to a reader or to Google, which discounts freshness signals that don't
 * track real change. Two gates:
 *
 * Later calendar day, in the site's timezone. Entry dates come from the filename and are
 * date-only (midnight America/Edmonton), so the save that publishes a review always lands
 * hours "after" it — comparing timestamps would mark every review as updated on day one,
 * and comparing days in mismatched timezones does the same to any evening save.
 *
 * Current festival only. Not because the stamp lies — because archive housekeeping is real
 * and constant: the July 2026 venue/title passes CP-saved dozens of 2024/2025 reviews
 * without changing a take. An archive review claiming "Updated: July 2026" over a re-linked
 * venue is exactly the worthless stamp this guards against. A show still running is where
 * an update means "I revised this take", and where a reader actually needs to know.
 */
class ReviewFreshness
{
    public static function for(EntryContract $entry): array
    {
        $reviewed = $entry->date();

        $stamp = $entry->value('updated_at');
        $modified = match (true) {
            ! $stamp => null,
            is_numeric($stamp) => Carbon::createFromTimestamp($stamp),
            default => Carbon::parse($stamp),
        };
        $modified?->setTimezone(config('app.timezone'));

        $festivalSlug = $entry->festival?->slug();
        $isCurrentFestival = $festivalSlug !== null && FestivalUrls::isCurrent($festivalSlug);

        $updated = $isCurrentFestival
            && $modified && $reviewed
            && $modified->toDateString() > $reviewed->copy()->setTimezone(config('app.timezone'))->toDateString()
                ? $modified
                : null;

        return array_filter([
            'reviewed_display' => $reviewed?->format('F j, Y'),
            'reviewed_iso' => $reviewed?->toIso8601String(),
            'updated_display' => $updated?->format('F j, Y'),
            'updated_iso' => $updated?->toIso8601String(),
        ]);
    }
}
