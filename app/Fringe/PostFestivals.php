<?php

namespace App\Fringe;

use App\Support\BardSets;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Which Fringe festivals a post covers.
 *
 * Two things need to know: the `/fringe` hub lists every Fringe post, and each year's
 * reviews index lists the posts about *that* year — most readers land on the reviews page
 * from search and never see the hub.
 *
 * The subject and the year are separate facts, so they aren't one tag. `topics: fringe` says
 * what the post is about and is what the hub lists on. The year is derived from the post
 * itself: the distinct festivals of the shows its `show` sets headline. A round-up cannot
 * then disagree with its own contents, and the common case needs no second tag at all.
 *
 * `fringe_festival` on the post is the override, for a post with no show sets ("the Fringe
 * changed its ticketing this year") or one whose subject year isn't the year of the shows
 * it happens to mention.
 *
 * Derived from `show` sets only, never from inline review pins — a pin is a citation inside
 * a sentence, so a 2026 post that mentions a 2024 show would otherwise file itself under
 * 2024. Same rule as the ItemList in App\Schema\PostSchema.
 *
 * @return array<int, string> festival slugs, newest first
 */
class PostFestivals
{
    public static function for($entry): array
    {
        if ($explicit = self::terms($entry)) {
            return $explicit;
        }

        return collect(BardSets::ofType($entry->value('content') ?? [], 'show'))
            ->map(fn ($set) => BardSets::first($set['review'] ?? null))
            ->filter()
            ->map(fn ($id) => EntryFacade::find($id))
            ->filter()
            ->map(fn ($review) => BardSets::first($review->value('festival')))
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    private static function terms($entry): array
    {
        return collect($entry->value('fringe_festival'))
            ->filter()
            ->map(fn ($term) => (string) $term)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }
}
