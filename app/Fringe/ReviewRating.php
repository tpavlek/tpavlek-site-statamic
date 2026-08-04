<?php

namespace App\Fringe;

use Statamic\Facades\Entry as EntryFacade;

/**
 * The star rating to display for a review, and the year it was actually earned.
 *
 * A returning show carries no rating of its own until Troy has seen this staging, so the
 * templates fall back to the original review's stars. That fallback used to be written
 * inline in Antlers, and it printed *this* festival's year beside the inherited stars —
 * so a pre-festival post read "Field Zoology 301 ★★★★★ (2026)" for a show that had not
 * opened. On a site whose pitch is that the reviews are correct, that is the worst
 * possible thing to get wrong.
 *
 * Exposed as the `rating` computed field on fringe_reviews. `year` is the festival the
 * stars come from, and `inherited` says whether they were borrowed, so a caller that
 * needs a verdict Troy has actually reached this year can refuse them.
 *
 * Zero stars is a real rating ("I walked out"), so emptiness is tested against null and
 * the empty string, never falsiness.
 */
class ReviewRating
{
    public static function for($entry): ?array
    {
        if ($own = self::stars($entry)) {
            return $own + ['year' => self::year($entry), 'inherited' => false];
        }

        $original = self::originalReview($entry);

        if ($original && ($inherited = self::stars($original))) {
            return $inherited + ['year' => self::year($original), 'inherited' => true];
        }

        return null;
    }

    /**
     * The select fieldtype augments to a LabeledValue, which is where the ★ glyphs live —
     * they're the option labels in the blueprint, not something the template builds.
     */
    private static function stars($entry): ?array
    {
        $labeled = $entry->augmentedValue('stars')?->value();
        $value = $labeled?->value();

        if ($value === null || $value === '') {
            return null;
        }

        return ['value' => (string) $value, 'label' => $labeled->label()];
    }

    private static function year($entry): ?string
    {
        $slug = $entry->value('festival');

        return is_array($slug) ? ($slug[0] ?? null) : $slug;
    }

    private static function originalReview($entry)
    {
        $id = $entry->value('original_review');
        $id = is_array($id) ? ($id[0] ?? null) : $id;

        return $id ? EntryFacade::find($id) : null;
    }
}
