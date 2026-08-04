<?php

namespace App\Schema;

use App\Support\BardSets;
use Statamic\Facades\Entry as EntryFacade;

/**
 * schema.org markup for a blog post.
 *
 * Registered as the `post_schema` computed field on the posts collection and emitted by
 * posts/show, so there is nothing to remember to switch on. Every post gets a BlogPosting;
 * a post that is built out of `show` sets — the round-up format, "six shows to watch" —
 * additionally gets an ItemList naming each show and pointing at its review.
 *
 * That ItemList is deliberately built from the `show` sets alone and not from inline
 * review pins. A set is the author saying "this post is a list, and this is an item in it";
 * a pin is a citation inside a sentence ("last year I saw The Stakeout"). Counting pins
 * would pad the list with shows the post isn't recommending, and a list that disagrees with
 * the visible page is worse than no list.
 *
 * Plain ListItems with a url, rather than nested Review objects, because this is a summary
 * page whose detail pages carry their own markup — the same shape the reviews index uses,
 * and the one Google documents for that structure. See App\Schema\FringeReviewSchema for
 * the per-show markup this points at.
 */
class PostSchema
{
    public static function build($entry): ?string
    {
        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $entry->value('title'),
            'url' => $entry->absoluteUrl(),
            'mainEntityOfPage' => $entry->absoluteUrl(),
            'description' => self::description($entry),
            'image' => self::image($entry),
            'datePublished' => $entry->date()?->toDateString(),
            'dateModified' => self::dateModified($entry),
            'author' => [
                '@type' => 'Person',
                'name' => 'Troy Pavlek',
                'url' => 'https://troypavlek.ca',
            ],
            'mainEntity' => self::showList($entry),
        ]);

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );
    }

    private static function showList($entry): ?array
    {
        $shows = self::reviews($entry);

        if ($shows->isEmpty()) {
            return null;
        }

        return [
            '@type' => 'ItemList',
            'itemListOrder' => 'https://schema.org/ItemListUnordered',
            'numberOfItems' => $shows->count(),
            'itemListElement' => $shows->values()->map(fn ($review, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => $review->absoluteUrl(),
                'name' => $review->value('title'),
            ])->all(),
        ];
    }

    /**
     * The reviews headlined by `show` sets, in the order they appear, de-duplicated in case
     * a post doubles back on a show it already covered.
     */
    private static function reviews($entry)
    {
        return collect(BardSets::ofType($entry->value('content') ?? [], 'show'))
            ->map(fn ($set) => BardSets::first($set['review'] ?? null))
            ->filter()
            ->unique()
            ->map(fn ($id) => EntryFacade::find($id))
            ->filter();
    }


    private static function description($entry): ?string
    {
        if ($value = $entry->value('og_description')) {
            return $value;
        }

        // bardText, not a cast to string: a post built out of sets augments to a node array
        // rather than HTML, and casting one is an "array to string conversion" fatal on the
        // whole page. bardText also skips set contents, which is what we want — the lead-in
        // paragraphs describe the post, the sets are its furniture.
        $text = \Statamic\Modifiers\Modify::value($entry->augmentedValue('content'))->bardText()->fetch();
        $text = html_entity_decode(trim((string) $text), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text !== '' ? \Statamic\Support\Str::truncate($text, 200) : null;
    }

    private static function image($entry): ?string
    {
        $image = $entry->augmentedValue('og_image')?->value();
        $image = is_iterable($image) ? collect($image)->first() : $image;

        return $image?->absoluteUrl();
    }

    /**
     * Omitted when it would predate publication, for the same reason as on a review: a post
     * modified before it was published is incoherent, and dateModified is optional.
     */
    private static function dateModified($entry): ?string
    {
        $modified = $entry->lastModified();
        $published = $entry->date();

        if (! $modified || ($published && $modified->lt($published))) {
            return null;
        }

        return $modified->toIso8601String();
    }

}
