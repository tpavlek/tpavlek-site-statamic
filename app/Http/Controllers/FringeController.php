<?php

namespace App\Http\Controllers;


use App\Fringe\FestivalUrls;
use App\Fringe\Reviews;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Intervention\Image\Facades\Image;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Fields\LabeledValue;
use Statamic\Taxonomies\LocalizedTerm;

class FringeController extends Controller
{

    public function generateSocialImage(string $entry)
    {
        $entry = EntryFacade::find($entry);

        Image::make(app_path("assets/{$entry->poster->path}"));

        dd($entry);
    }

    /**
     * The stable, year-agnostic reviews URL, and the canonical home of the current
     * festival's reviews.
     *
     * This used to 302 to /fringe/{year}/reviews. It serves the content directly now
     * because the redirect meant the one URL that never changes accumulated no ranking
     * signal: Search Console showed the head terms ("edmonton fringe reviews",
     * "fringe reviews", "edmonton international fringe festival reviews") all landing on
     * whichever year page Google happened to pick, stuck at positions 11-14 and clicking
     * through at well under 1%. Every August that page became a year staler against a
     * query with no year in it. Pointing the head terms at one URL that is always current
     * is the fix.
     */
    public function currentYear()
    {
        $slug = FestivalUrls::currentSlug();

        $festival = TermFacade::find("fringe_festival::{$slug}");

        abort_if(! $festival, 404);

        return $this->yearReviews($festival, $slug, "fringe-{$slug}");
    }

    /**
     * Every festival year's reviews page. Adding a year is now a matter of creating the
     * fringe_festival term; no route or controller change needed.
     *
     * While a year is the current festival its reviews live only at /fringe/reviews and
     * this URL redirects there, so the content exists at exactly one address. Redirecting
     * rather than serving a copy that points its canonical elsewhere is deliberate: a
     * redirect is a directive Google obeys, a canonical is a hint it can overrule.
     *
     * 302, not 301: the moment the next year's term exists this URL stops redirecting and
     * starts serving its own archive, and a cached permanent redirect would outlive that.
     */
    public function year(string $year)
    {
        $festival = TermFacade::find("fringe_festival::{$year}");

        abort_if(! $festival, 404);

        if (FestivalUrls::isCurrent($year)) {
            return redirect(FestivalUrls::EVERGREEN, 302);
        }

        return $this->yearReviews($festival, $year, "fringe-{$year}");
    }

    /**
     * A review's festival year, read straight off the entry.
     *
     * The augmented `$entry->festival->slug` resolves a taxonomy term to get at the same
     * string, which is fine once and expensive across a collection that now holds every show
     * at the festival. Stored as a single-value taxonomy field, so it can arrive as either a
     * string or a one-element array depending on how the entry was written.
     */
    private static function festivalSlugOf(Entry $entry): ?string
    {
        $slug = $entry->value('festival');

        return is_array($slug) ? ($slug[0] ?? null) : $slug;
    }

    /**
     * For a returning show, the entry of the review from the year I originally saw it.
     */
    private function originalReview(Entry $entry): ?\Statamic\Contracts\Entries\Entry
    {
        $id = $entry->value('original_review');

        if (is_array($id)) {
            $id = $id[0] ?? null;
        }

        return $id ? EntryFacade::find($id) : null;
    }

    /**
     * Whether the show carries the improv category, which is what earns it the "Improv*"
     * badge in fringe/_review-tag when it has no rating of its own.
     */
    private function isImprov(Entry $entry): bool
    {
        return $entry->categories?->contains(fn (LocalizedTerm $term) => $term->slug === 'improv') ?? false;
    }

    private function yearReviews($festival, string $festivalSlug, string $videoCategorySlug)
    {
        // Published only — see App\Fringe\Reviews. This list feeds the visible table, the
        // review count and the page's JSON-LD ItemList, so a draft leaking in would put a
        // 404 into structured data.
        $reviews = Reviews::published()
            // The raw value, not `$entry->festival->slug`. The augmented form resolves a
            // taxonomy term per entry to read a year that's already sitting in the file —
            // 30ms across the collection against 0.2ms, for an identical result.
            ->filter(function (Entry $entry) use ($festivalSlug) {
                return self::festivalSlugOf($entry) === $festivalSlug;
            })
            ->sortByDesc(function (Entry $entry) {
                // We sort by stars on a 0-50 scale. A returning show without a fresh rating
                // inherits the original review's stars.
                $stars = $entry->stars->value() ?: $this->originalReview($entry)?->stars->value();

                if ($stars) {
                    return (float) $stars * 10;
                }

                // Unrated shows slot onto the same scale. The buckets mirror the badges in
                // fringe/_review-tag, and are checked in the same order, so the list reads the
                // way the badges suggest. Watchlist and improv both sit just above a 3-star
                // show, watchlist first: a show Troy picked out unseen is a better bet than
                // "it's improv, you get what you get".
                return match (true) {
                    // Below everything, including a one-star show. `pending` means the show
                    // was imported from the ticket site and never looked at, so it carries
                    // no opinion at all and can't be ranked among ones that do. These are
                    // drafts and shouldn't reach this list — but if one is ever published by
                    // accident it sinks rather than floating above the rated shows.
                    Reviews::isPending($entry) => 0,
                    $entry->recommendation->value() === 'watchlist' => 32,
                    $this->isImprov($entry) => 31,
                    default => 35,
                };
            });

        // Raw category slugs rather than `$entry->category`, which resolves a taxonomy term
        // for every video on the site — 109ms against 8ms, and it was the single most
        // expensive thing on the reviews page despite having nothing to do with reviews.
        //
        // Not `whereTaxonomy()`, tempting as it is at 0.4ms: `video_category` isn't declared
        // on the videos collection, only referenced by a blueprint field, so the taxonomy
        // index is empty and the query returns **nothing for every category** — silently, and
        // for fringe-2026 it happens to agree with the correct answer because both are empty.
        // Declaring the taxonomy on the collection would make it usable; until then this is
        // the version that returns the right videos.
        $videos = EntryFacade::query()
            ->where('collection', 'videos')
            ->get()
            ->filter(function (Entry $entry) use ($videoCategorySlug) {
                return in_array($videoCategorySlug, (array) ($entry->value('category') ?? []), true);
            });

        $posts = $this->posts($festivalSlug);

        $lastUpdated = $this->lastUpdated($reviews) ?? $festival?->lastModified();

        $ratedCount = $reviews->filter(fn (Entry $entry) => $entry->stars->value() !== null)->count();

        // Each page is canonical to itself. Nothing cross-canonicalizes: the current festival
        // only ever renders at /fringe/reviews and every other year only ever at its own URL,
        // so there is no duplicate to point away from. FestivalUrls already knows which is
        // which, which is why there's no branch here.
        $canonical = FestivalUrls::absoluteReviews($festivalSlug);

        // Two rungs — the /fringe hub and this page. The show pages hang a third off the same
        // builder, so the trail reads consistently across the section.
        $breadcrumbs = \App\Schema\BreadcrumbSchema::forReviewsIndex($festivalSlug);

        // Page metadata lives on the festival term rather than a stub page entry. Those
        // entries held nothing but a title and og tags, and their filename-derived slugs
        // were what produced duplicate, controller-less copies of this page.
        //
        // The year sits in the tagline rather than a "(2025)" stamp after the name. The
        // problem was never the year itself, it was a *frozen* year: the old page kept
        // saying 2025 into 2026, and Search Console showed "edmonton fringe reviews 2025"
        // converting at 16% while the unqualified query managed 0.5% from a better
        // position. On /fringe/reviews this always renders the current festival, so it
        // matches both shapes of the query and is never stale.
        $title = $festival?->value('og_title')
            ?: "Edmonton Fringe Reviews | The best shows at the {$festivalSlug} Fringe";

        // Concrete beats vague. "best fringe shows" was sitting at position 6 with zero
        // clicks on the old description, which promised reviews without saying how many
        // or what they'd tell you.
        $description = $festival?->value('og_description') ?: ($ratedCount > 0
            ? "{$ratedCount} shows at the {$festivalSlug} Edmonton Fringe, each rated out of five. Honest takes, so you can find one worth your ticket."
            : "Every show I see at the {$festivalSlug} Edmonton Fringe, rated out of five. Honest takes, so you can find one worth your ticket.");

        return (new \Statamic\View\View)
            ->template('fringe/index')
            ->layout('layout')
            ->with([
                'reviews' => $reviews,
                'videos' => $videos,
                'posts' => $posts,
                'year' => $festivalSlug,
                'tickets_available' => (bool) $festival?->value('tickets_available'),
                'review_count' => $reviews->count(),
                'rated_count' => $ratedCount,
                'last_updated_display' => $lastUpdated?->format('F j, Y'),
                'last_updated_iso' => $lastUpdated?->toIso8601String(),
                'canonical_url' => $canonical,
                // Only the current festival has a feed — see the route comment. An archive
                // year advertising one would promise updates that will never come.
                'feed_url' => FestivalUrls::isCurrent($festivalSlug) ? '/fringe/reviews/feed.xml' : null,
                'feed_title' => "Edmonton Fringe Reviews ({$festivalSlug})",
                'breadcrumbs' => $breadcrumbs,
                'breadcrumb_schema' => \App\Schema\BreadcrumbSchema::build($breadcrumbs),
                'structured_data' => $this->structuredData($title, $description, $reviews, $festivalSlug, $festival, $lastUpdated, $canonical),
                'title' => $title,
                'og_title' => $title,
                'og_description' => $description,
                // Built with the card generator (App\Og\CardRenderer), like every other
                // sharing image on the site. This page is a controller route rather than an
                // entry, so there's no CP action for it — the command that made it is:
                //
                //   php artisan og:card --out=og/fringe-reviews.png \
                //     --headline="Troy's Fringe Reviews" \
                //     --subhead="The most excellent Fringe reviews you will find. It says so right here." \
                //     --images=six-shows-to-watch-at-fringe-2026/edmontask-feed.png \
                //     --images=six-shows-to-watch-at-fringe-2026/sketchy-broads-presents-resting-bitumen-face-feed.png \
                //     --images=six-shows-to-watch-at-fringe-2026/2026-field-zoology-301-feed.png \
                //     --portrait=fringe/fringe-with-atlas-2026.jpg --portrait-focus=15%
                //
                // Show art says what the reviews are about; a face says who is doing the
                // reviewing, and this is a page that lives or dies on whether you trust the
                // reviewer. `--portrait-focus=15%` is where the square crop keeps his whole
                // face, the programme and the cat — the default centre cuts his face off and
                // leaves the cat as the subject, and past about 20% his cheek starts to clip.
                //
                // An array with a bare `url` rather than an asset, because the layout needs
                // an absolute URL here and this is the one place that knows the origin.
                'og_image' => ['url' => FestivalUrls::absolute('/assets/og/fringe-reviews.png')],
            ]);
    }

    /**
     * Fringe posts about this festival, newest first.
     *
     * Most readers arrive here from search and never see the /fringe hub, so the writing has
     * to be reachable from the page they actually land on.
     *
     * Filtered in PHP rather than queried: `festivals` is a computed field, and computed
     * fields aren't in the Stache index. The posts collection is small enough that this
     * doesn't matter, and the alternative is a second tag to keep in sync by hand.
     */
    private function posts(string $festivalSlug): Collection
    {
        return EntryFacade::query()
            ->where('collection', 'posts')
            ->where('published', true)
            ->get()
            ->filter(fn (Entry $entry) => $entry->topics?->contains(fn (LocalizedTerm $term) => $term->slug === 'fringe')
                && in_array($festivalSlug, $entry->augmentedValue('festivals')->value() ?? [], true))
            ->sortByDesc(fn (Entry $entry) => $entry->date())
            ->values();
    }

    /**
     * The most recently touched review in the list — the page's freshness date.
     */
    private function lastUpdated(Collection $reviews): ?Carbon
    {
        return $reviews
            ->map(fn (Entry $entry) => $entry->lastModified())
            ->filter()
            ->max();
    }

    /**
     * JSON-LD for the reviews index.
     *
     * The list itself is a summary page, so each item is a plain ListItem pointing
     * at the show's own page — the full Review markup (rating, author) lives there,
     * which is the structure Google documents for list-plus-detail pages.
     */
    private function structuredData(string $title, string $description, Collection $reviews, string $festivalSlug, $festival, ?Carbon $lastUpdated, string $canonical): string
    {
        $items = $reviews
            ->values()
            ->map(fn (Entry $entry, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => $entry->absoluteUrl(),
                'name' => $entry->value('title'),
            ])
            ->all();

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $title,
            'description' => $description,
            // The canonical, not request()->url(): the current year answers on two URLs and
            // the markup should name the one that owns the content.
            'url' => $canonical,
            'dateModified' => $lastUpdated?->toIso8601String(),
            'inLanguage' => 'en-CA',
            'author' => [
                '@type' => 'Person',
                'name' => 'Troy Pavlek',
                'url' => 'https://troypavlek.ca',
            ],
            // Festival is an Event subtype, so Google validates it as one and startDate is
            // required — Search Console flagged this page over it. A term with no dates
            // emits no `about` at all rather than an Event that can't validate.
            'about' => $festival?->value('starts_at') ? array_filter([
                '@type' => 'Festival',
                'name' => "Edmonton International Fringe Theatre Festival {$festivalSlug}",
                'startDate' => $festival->value('starts_at'),
                'endDate' => $festival->value('ends_at'),
                'location' => [
                    '@type' => 'Place',
                    'name' => 'Edmonton, Alberta',
                ],
            ]) : null,
            'mainEntity' => [
                '@type' => 'ItemList',
                'name' => "Edmonton Fringe {$festivalSlug} reviews",
                'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ],
        ]);

        // HEX_TAG so a show title containing "</script>" can't break out of the tag.
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT);
    }

}
