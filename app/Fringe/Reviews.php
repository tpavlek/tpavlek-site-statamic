<?php

namespace App\Fringe;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Which fringe_reviews entries the public site is allowed to see.
 *
 * The collection holds three very different things since the lineup import:
 *
 *   published            a review Troy wrote. Has a page, belongs everywhere.
 *   published + `exists` a show Troy is NOT reviewing but wants a live page for — so other
 *                        pages can link it and its availability card renders. It is not a
 *                        review and not an endorsement, so it belongs on its own page and
 *                        nowhere else: not the reviews index, not the feed, not artist
 *                        pages, not the sitemap (see sitemapFilter), and its page is
 *                        noindexed (computed `noindex` in AppServiceProvider).
 *   draft + `pending`    a show imported from the ticket site and not looked at. It is the
 *                        festival lineup, not an opinion. Its URL 404s (Statamic does that
 *                        for drafts) and the sitemap skips it (the generator filters on
 *                        status), so the only way it can reach the public site is a query
 *                        that forgets to exclude it.
 *
 * `Entry::query()` includes drafts by default, so "forgets to exclude it" is the default
 * behaviour — which is why this lives in one place rather than as a `->where('published',
 * true)` repeated at each call site. Miss one and the failure isn't a visible error: the
 * reviews index grows by 190 rows, or the feed carries contentless items, or an artist page
 * links to shows that 404, or — worst — the index's JSON-LD hands Google an ItemList of 404s.
 *
 * Two signals rather than one on purpose. `published` is what suppresses the page; `pending`
 * is what distinguishes an untouched import from a review Troy is halfway through writing,
 * which is also a draft and must not show up in the lineup table.
 */
class Reviews
{
    /**
     * Reviews with a page — everything the public site should treat as a review.
     *
     * @return Collection<int, EntryContract>
     */
    public static function published(): Collection
    {
        // Filtered by the query rather than in PHP. `all()->filter(->published())` augments
        // every one of the ~270 entries to answer a question the Stache index already knows,
        // which measured 48ms against 6ms on the reviews index — the page this feeds.
        return EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->where('published', true)
            ->get();
    }

    /**
     * Reviews the public listings should carry: published, minus `exists` pages.
     *
     * This is what the reviews index, the RSS feed and the artist pages want. An `exists`
     * entry has a perfectly good page — that's its whole point — but it isn't a review,
     * so listing it anywhere would present "I'm not covering this" as coverage.
     *
     * @return Collection<int, EntryContract>
     */
    public static function reviewed(): Collection
    {
        return self::published()
            ->reject(fn (EntryContract $entry) => self::isExists($entry))
            ->values();
    }

    public static function isExists(EntryContract $entry): bool
    {
        $value = $entry->value('recommendation');
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return $value === 'exists';
    }

    /**
     * pecotamic/sitemap `filter` callback (configured as a static-method callable so
     * `config:cache` can serialize it). Keeps everything except `exists` pages — they're
     * live and linkable, but they're thin near-empty pages and shouldn't be offered to
     * crawlers. Non-fringe entries pass straight through.
     */
    public static function sitemapFilter($entry): bool
    {
        if (! $entry instanceof EntryContract || $entry->collectionHandle() !== 'fringe_reviews') {
            return true;
        }

        return ! self::isExists($entry);
    }

    /**
     * The imported lineup: shows that exist as entries but have no review and no page.
     *
     * Drafts that are *not* pending are deliberately excluded — those are reviews in
     * progress, and a half-written review has no business on the site in any form.
     *
     * @return Collection<int, EntryContract>
     */
    public static function pending(): Collection
    {
        return self::all()
            ->filter(fn (EntryContract $entry) => ! $entry->published() && self::isPending($entry))
            ->values();
    }

    public static function isPending(EntryContract $entry): bool
    {
        $value = $entry->value('recommendation');
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return $value === 'pending';
    }

    /**
     * Every entry, published or not. For the CP and for callers that have already decided
     * what they want — front-end code should be reaching for published() or pending().
     *
     * @return Collection<int, EntryContract>
     */
    public static function all(): Collection
    {
        return EntryFacade::query()->where('collection', 'fringe_reviews')->get();
    }
}
