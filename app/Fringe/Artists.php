<?php

namespace App\Fringe;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Which artists get a page of their own, and what's on it.
 *
 * People search Fringe companies by name — "martin dockery fringe", "is the new sketchy
 * broads any good" — and until now the site had 48 artist entries with no URLs at all, so
 * those queries had nothing here to land on. An artist page is built entirely from reviews
 * that already exist and needs no new writing to be worth reading.
 *
 * Two gates, because the failure mode of an entity section is thin pages that dilute the
 * whole site rather than adding to it:
 *
 *   A real name. The ticket importer creates artists titled with their Instagram handle and
 *   leaves them that way until someone fills the name in. "weirdalkaraokeyeg" is not a name,
 *   nobody searches it, and a page titled with it looks like a bug. fringe/review already
 *   applies this exact test before printing an artist's name.
 *
 *   More than one show. A single-show artist page is a worse copy of the review it links to
 *   — same title, same rating, none of the writing. Two or more is the point at which the
 *   page says something no single review does: what this company keeps doing, and whether
 *   they're getting better.
 *
 * Both gates are re-evaluated on every request, so a company that comes back next August
 * gets a page the moment their second review is published, and an artist gets one the moment
 * someone types their real name into the CP. Nothing to remember to turn on.
 */
class Artists
{
    /**
     * Reviews grouped by artist, held for the request. See reviewsByArtist().
     *
     * @var Collection<string, Collection<int, EntryContract>>|null
     */
    private static ?Collection $cache = null;

    /**
     * Every artist with a page, keyed by nothing in particular — callers want the list.
     *
     * @return Collection<int, EntryContract>
     */
    public static function withPages(): Collection
    {
        $counts = self::reviewsByArtist()->map->count();

        return EntryFacade::query()
            ->where('collection', 'artists')
            ->get()
            ->filter(fn (EntryContract $artist) => self::hasRealName($artist)
                && ($counts[$artist->id()] ?? 0) >= 2)
            ->sortBy(fn (EntryContract $artist) => mb_strtolower((string) $artist->value('title')))
            ->values();
    }

    /**
     * The artist for a slug, or null when they don't qualify for a page.
     *
     * Deliberately the same null for "no such artist" and "artist exists but has one show":
     * both are a 404, and distinguishing them in the URL space would leak the existence of
     * entries that have no page.
     */
    public static function find(string $slug): ?EntryContract
    {
        return self::withPages()->first(fn (EntryContract $artist) => self::slug($artist) === $slug);
    }

    /**
     * An artist's shows, newest festival first.
     *
     * @return Collection<int, EntryContract>
     */
    public static function reviews(EntryContract $artist): Collection
    {
        return self::reviewsByArtist()
            ->get($artist->id(), collect())
            ->sortByDesc(fn (EntryContract $review) => [
                self::festivalOf($review),
                // Within a festival a company's shows have no inherent order, so sort by
                // title to keep it stable rather than at the mercy of file order.
                mb_strtolower((string) $review->value('title')),
            ])
            ->values();
    }

    /**
     * An artist's shows, one row per *work* rather than per staging, newest first.
     *
     * A returning show is one show. Listing Field Zoology 301 twice because Troy saw it in
     * 2024 and again in 2026 makes a company look like it has twice the output it does, and
     * on a page whose whole job is "what has this company brought", repeats are noise.
     *
     * Each returned entry is the most recent staging, carrying an `earlier_stagings`
     * supplement holding the rest, newest first. They stay linked rather than hidden: each
     * is a real review with its own page, and dropping them would cost the internal links
     * that make those pages findable.
     *
     * Two shows count as one work if either:
     *
     *   they're joined by original_review — the explicit "this show returned" link, which
     *   also covers a recurring show whose subtitle changes every year (100% Wizard →
     *   110% Wizard); or
     *
     *   the same artist staged them under exactly the same title. The original_review sweep
     *   only ran over the 2026 shows, so a show that ran in 2024 and 2025 was never linked
     *   to itself — Late Night Cabaret is three identically-titled entries with one link
     *   between them.
     *
     * Titles are only merged when they match exactly. Nothing looser is safe: Sketchy Broads
     * have brought "Choosing the Bear", "Easy Bake Coven" and "Resting Bitumen Face", which
     * share a prefix and are three different shows.
     *
     * @return Collection<int, EntryContract>
     */
    public static function works(EntryContract $artist): Collection
    {
        $reviews = self::reviews($artist);

        $groups = self::group($reviews);

        return $groups
            ->map(function (Collection $staging) {
                // reviews() already sorted newest first, and grouping preserves that order.
                $latest = $staging->first();

                return $latest->setSupplement('earlier_stagings', $staging->slice(1)->values());
            })
            ->values();
    }

    /**
     * Partition an artist's reviews into works. See works() for the two rules.
     *
     * @param  Collection<int, EntryContract>  $reviews
     * @return Collection<int, Collection<int, EntryContract>>
     */
    private static function group(Collection $reviews): Collection
    {
        $keyed = $reviews->keyBy->id();

        // Union-find, so a chain of any length collapses to one work — 2026 links to 2025
        // links to 2024 has to end up as one group, not two.
        $parent = [];
        $find = function (string $id) use (&$parent, &$find): string {
            return $parent[$id] === $id ? $id : $parent[$id] = $find($parent[$id]);
        };
        $union = function (string $a, string $b) use (&$parent, $find) {
            $parent[$find($a)] = $find($b);
        };

        foreach ($keyed as $id => $review) {
            $parent[$id] = $id;
        }

        foreach ($keyed as $id => $review) {
            $original = $review->value('original_review');
            $original = is_array($original) ? ($original[0] ?? null) : $original;

            // Only when the original is by this same artist. A show can point at a review
            // that isn't in this list at all, and unioning against an absent id would build
            // a group around a review the page never shows.
            if ($original && $keyed->has($original)) {
                $union($id, $original);
            }
        }

        foreach ($reviews->groupBy(fn (EntryContract $review) => (string) $review->value('title')) as $sameTitle) {
            $first = $sameTitle->first()->id();

            foreach ($sameTitle as $review) {
                $union($review->id(), $first);
            }
        }

        return $reviews
            ->groupBy(fn (EntryContract $review) => $find($review->id()))
            ->values();
    }

    /**
     * The URL slug, derived from the artist's name rather than the entry's slug.
     *
     * The entry slug comes from the ticket importer, which creates artists from their
     * Instagram handle — so the stored slugs are "martindockery1", "keithhbrown", "satco".
     * Those are fine as filenames and useless as URLs for a page whose entire job is to rank
     * for a person's name. This is the same split fringe_reviews already makes with its
     * `url_slug` computed field: the file is named one way, the URL reads another.
     *
     * Two artists whose names slugify identically would collide. Nothing in the data does
     * today, and the fix when it happens is to rename one of them, not to hang a numeric
     * suffix on a URL a human is meant to read.
     */
    public static function slug(EntryContract $artist): string
    {
        return Str::slug((string) $artist->value('title'));
    }

    public static function url(EntryContract $artist): string
    {
        return '/fringe/artists/'.self::slug($artist);
    }

    /**
     * The importer's placeholder is the Instagram handle, so a title equal to the handle
     * means nobody has filled a name in yet.
     */
    public static function hasRealName(EntryContract $artist): bool
    {
        $title = (string) $artist->value('title');

        return $title !== '' && $title !== (string) $artist->value('instagram');
    }

    /**
     * Every review grouped by artist id.
     *
     * Grouped once and held for the request: withPages() needs a count per artist, and doing
     * that as a query per artist would be 48 passes over the collection to render one page.
     *
     * @return Collection<string, Collection<int, EntryContract>>
     */
    private static function reviewsByArtist(): Collection
    {
        // Published only, and it matters twice here: an imported lineup entry would both
        // put a link to a 404 on an artist's page and count toward the two-show gate, so a
        // company with one review and two unlooked-at imports would earn a page listing two
        // shows that don't exist. See App\Fringe\Reviews.
        return self::$cache ??= Reviews::reviewed()
            ->groupBy(fn (EntryContract $review) => (string) self::artistIdOf($review))
            ->forget('');
    }

    private static function artistIdOf(EntryContract $review): ?string
    {
        $id = $review->value('artist');

        return is_array($id) ? ($id[0] ?? null) : $id;
    }

    private static function festivalOf(EntryContract $review): ?string
    {
        $slug = $review->value('festival');

        return is_array($slug) ? ($slug[0] ?? null) : $slug;
    }

    /**
     * Only for tests, which publish reviews between cases within one process.
     */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
