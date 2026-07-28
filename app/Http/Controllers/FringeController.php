<?php

namespace App\Http\Controllers;


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
     * The stable, year-agnostic reviews URL. Redirects to whichever festival is
     * current, so links shared in the wild keep working every August.
     *
     * Temporary, not permanent: the target changes each year, and a 301 would be
     * cached by browsers past the point where it's true.
     */
    public function currentYear()
    {
        return redirect('/fringe/'.$this->currentFestivalSlug().'/reviews');
    }

    private function currentFestivalSlug(): string
    {
        return TermFacade::query()
            ->where('taxonomy', 'fringe_festival')
            ->get()
            ->sortByDesc(fn (LocalizedTerm $term) => (int) $term->slug())
            ->first()
            ?->slug() ?? '2026';
    }

    /**
     * Every festival year's reviews page. Adding a year is now a matter of creating the
     * fringe_festival term; no route or controller change needed.
     */
    public function year(string $year)
    {
        $festival = TermFacade::find("fringe_festival::{$year}");

        abort_if(! $festival, 404);

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

        // Page metadata lives on the festival term rather than a stub page entry. Those
        // entries held nothing but a title and og tags, and their filename-derived slugs
        // were what produced duplicate, controller-less copies of this page.
        $title = $festival?->value('og_title')
            ?: "Edmonton Fringe Reviews ({$festivalSlug}) | The best shows at the Fringe";

        $description = $festival?->value('og_description')
            ?: 'The reviews and recommendations you need to get into a great Fringe show';

        return (new \Statamic\View\View)
            ->template('fringe/index')
            ->layout('layout')
            ->with([
                'reviews' => $reviews,
                'videos' => $videos,
                'year' => $festivalSlug,
                'tickets_available' => (bool) $festival?->value('tickets_available'),
                'review_count' => $reviews->count(),
                'rated_count' => $reviews->filter(fn (Entry $entry) => $entry->stars->value() !== null)->count(),
                'last_updated_display' => $lastUpdated?->format('F j, Y'),
                'last_updated_iso' => $lastUpdated?->toIso8601String(),
                'structured_data' => $this->structuredData($title, $description, $reviews, $festivalSlug, $lastUpdated),
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
    private function structuredData(string $title, string $description, Collection $reviews, string $festivalSlug, ?Carbon $lastUpdated): string
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
            'url' => request()->url(),
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
