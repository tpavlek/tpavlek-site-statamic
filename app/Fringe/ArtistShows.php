<?php

namespace App\Fringe;

/**
 * The other shows a company has brought to the Fringe.
 *
 * Exposed as the `previous_shows` computed field on fringe_reviews, and used for the
 * "Previously:" line under a show heading in a post.
 *
 * This exists because the honest answer to "scope the previous-shows dropdown to the same
 * artist" is that the relationship fieldtype can't do it: a field cannot see its siblings'
 * values when its CP metadata is built, and that metadata is generated on page load, so even
 * a hack would only re-scope after a save. But the premise was right — an earlier staging is
 * by definition the same company — and if the answer is derivable there is nothing to pick.
 * The set's `previous_reviews` field stays as an override for the cases where the list should
 * be a specific subset, or a company that changed names.
 *
 * Earlier festivals only. A company often has two shows in one year and the second is not
 * "previous", it's the thing playing in the next venue over.
 */
class ArtistShows
{
    private const LIMIT = 3;

    public static function previous($entry)
    {
        $artist = self::artist($entry);
        $festival = self::festival($entry);

        if (! $artist || ! $festival) {
            return collect();
        }

        // Reviews only: a "Previously:" line pointing at an imported lineup entry would be a
        // link to a 404, and an `exists` page is not a prior review. See App\Fringe\Reviews.
        return Reviews::reviewed()
            ->filter(fn ($other) => $other->id() !== $entry->id()
                && self::artist($other) === $artist
                && ($year = self::festival($other))
                && $year < $festival
                // A restaging under the same name is already announced by the returning
                // badge. Listing it again as "Previously" states the same fact a third time.
                // A renamed follow-up — 100% Wizard becoming 110% Wizard — still belongs.
                && $other->value('title') !== $entry->value('title'))
            ->sortByDesc(fn ($other) => self::festival($other))
            ->take(self::LIMIT)
            ->values();
    }

    private static function artist($entry): ?string
    {
        $id = $entry->value('artist');

        return is_array($id) ? ($id[0] ?? null) : $id;
    }

    private static function festival($entry): ?string
    {
        $slug = $entry->value('festival');

        return is_array($slug) ? ($slug[0] ?? null) : $slug;
    }
}
