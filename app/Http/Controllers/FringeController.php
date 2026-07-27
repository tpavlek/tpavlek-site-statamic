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
        return $this->yearReviews('2d1ff3e5-2587-4ff3-a46b-a3fd0f87910c', '2026', 'fringe-2026');
    }

    public function year2025()
    {
        return $this->yearReviews('0e886b54-f9f8-4421-96a3-1af5568a9866', '2025', 'fringe-2025');
    }

    public function year2024()
    {
        return $this->yearReviews('5ccd2e90-a7f6-4d71-ba92-9783469febec', '2024', 'fringe-2024');
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

    private function yearReviews(string $landingPageId, string $festivalSlug, string $videoCategorySlug)
    {
        $page = EntryFacade::query()
            ->where('collection', 'pages')
            ->where('parent', $landingPageId)
            ->where('slug', 'reviews')
            ->first();

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
