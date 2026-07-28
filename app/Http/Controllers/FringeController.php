<?php

namespace App\Http\Controllers;


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

    public function year2026()
    {
        return $this->yearReviews('2490f7bc-36fe-4846-9f52-2374c8886e74', '2026', 'fringe-2026');
    }

    public function year2025()
    {
        return $this->yearReviews('3251fd42-35da-45f5-a189-f98809d2f488', '2025', 'fringe-2025');
    }

    public function year2024()
    {
        return $this->yearReviews('754a4add-f747-4b84-9d15-83f19faf505e', '2024', 'fringe-2024');
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

    private function yearReviews(string $reviewsPageId, string $festivalSlug, string $videoCategorySlug)
    {
        $page = EntryFacade::find($reviewsPageId);

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

        $festival = TermFacade::find("fringe_festival::{$festivalSlug}");

        return (new \Statamic\View\View)
            ->template('fringe/index')
            ->layout('layout')
            ->with([
                'reviews' => $reviews,
                'videos' => $videos,
                'year' => $festivalSlug,
                'tickets_available' => (bool) $festival?->value('tickets_available'),
            ])
            ->cascadeContent($page);
    }

}
