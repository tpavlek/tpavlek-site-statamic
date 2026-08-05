<?php

namespace App\Fringe;

use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * When a review was written, and whether it has been touched since.
 *
 * A review page carried no visible date at all — its only freshness signal was the
 * datePublished buried in the JSON-LD, which does nothing for a reader deciding whether a
 * take on a show still running is current.
 *
 * "Updated" is deliberately hard to earn, because an update stamp that everything wears is
 * worth nothing — to a reader or to Google, which discounts freshness signals that don't
 * track real change. Two gates:
 *
 * Later calendar day. Entry dates come from the filename and so are date-only, meaning a
 * same-day edit always lands a few hours "after" midnight. Comparing raw timestamps would
 * mark every review ever written as updated.
 *
 * Current festival only. lastModified is a filesystem fact, not an editorial one: a bulk
 * migration touches every file at once. The venue migration alone re-dated all 56 of the
 * 2024 and 2025 reviews to a single day in July 2026, none of which had a word changed.
 * Archive reviews therefore show their review date and nothing else, which is the honest
 * claim; a show still running is where an update means "I revised this take", and where a
 * reader actually needs to know.
 */
class ReviewFreshness
{
    public static function for(EntryContract $entry): array
    {
        $reviewed = $entry->date();
        $modified = $entry->lastModified();

        // Explicitly not FestivalUrls::isCurrent() alone: it reads a null year as "the current
        // festival", which is right for URL building and wrong here — a review with no
        // festival term should claim nothing.
        $festivalSlug = $entry->festival?->slug();
        $isCurrentFestival = $festivalSlug !== null && FestivalUrls::isCurrent($festivalSlug);

        $updated = $isCurrentFestival
            && $modified && $reviewed
            && $modified->startOfDay()->gt($reviewed->copy()->startOfDay())
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
