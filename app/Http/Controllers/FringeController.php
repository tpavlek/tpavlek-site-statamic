<?php

namespace App\Http\Controllers;


use App\Fringe\FestivalUrls;
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

    private function yearReviews($festival, string $festivalSlug, string $videoCategorySlug)
    {
        $reviews = EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->filter(function (Entry $entry) use ($festivalSlug) {
                return $entry->festival->slug === $festivalSlug;
            })
            ->sortByDesc(function (Entry $entry) {
                // We sort by stars, but if we don't have a stars, "not recommended" is 0-stars, "recommended" is 3.5 stars
                // A returning show without a fresh rating inherits the original review's stars
                $stars = $entry->stars->value() ?: $this->originalReview($entry)?->stars->value();

                // Watchlist shows haven't been seen yet — list them after everything reviewed
                if (! $stars && $entry->recommendation->value() === 'watchlist') {
                    return 0;
                }

                return $stars ? (float)$stars * 10 : 35;
            });

        $videos = EntryFacade::query()
            ->where('collection', 'videos')
            ->get()
            ->filter(function (Entry $entry) use ($videoCategorySlug) {
                return $entry->category->contains(fn (LocalizedTerm $term) => $term->slug === $videoCategorySlug);
            });

        $lastUpdated = $this->lastUpdated($reviews) ?? $festival?->lastModified();

        $ratedCount = $reviews->filter(fn (Entry $entry) => $entry->stars->value() !== null)->count();

        // Each page is canonical to itself. Nothing cross-canonicalizes: the current festival
        // only ever renders at /fringe/reviews and every other year only ever at its own URL,
        // so there is no duplicate to point away from. FestivalUrls already knows which is
        // which, which is why there's no branch here.
        $canonical = FestivalUrls::absoluteReviews($festivalSlug);

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
                'year' => $festivalSlug,
                'tickets_available' => (bool) $festival?->value('tickets_available'),
                'review_count' => $reviews->count(),
                'rated_count' => $ratedCount,
                'last_updated_display' => $lastUpdated?->format('F j, Y'),
                'last_updated_iso' => $lastUpdated?->toIso8601String(),
                'canonical_url' => $canonical,
                'structured_data' => $this->structuredData($title, $description, $reviews, $festivalSlug, $lastUpdated, $canonical),
                'title' => $title,
                'og_title' => $title,
                'og_description' => $description,
                'og_image' => ['url' => 'https://troypavlek.ca/assets/og-fringe-reviews.jpeg'],
            ]);
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
    private function structuredData(string $title, string $description, Collection $reviews, string $festivalSlug, ?Carbon $lastUpdated, string $canonical): string
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
            'about' => [
                '@type' => 'Festival',
                'name' => "Edmonton International Fringe Theatre Festival {$festivalSlug}",
                'location' => [
                    '@type' => 'Place',
                    'name' => 'Edmonton, Alberta',
                ],
            ],
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
