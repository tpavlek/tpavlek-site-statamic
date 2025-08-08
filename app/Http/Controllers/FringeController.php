<?php

namespace App\Http\Controllers;


use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Fields\LabeledValue;

class FringeController extends Controller
{

    public function currentYear()
    {

        $page = EntryFacade::query()
            ->where('collection', 'pages')
            ->where('slug', 'reviews')
            ->first();

        $reviews = EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->filter(function (Entry $entry) {
                return $entry->festival->slug === '2025';
            })
            ->sortByDesc(function (Entry $entry) {
                // We sort by stars, but if we don't have a stars, "not recommended" is 0-stars, "recommended" is 3.5 stars
                return ($entry->stars->value()) ? (float)$entry->stars->value() * 10 : 35;
            });

        return (new \Statamic\View\View)
            ->template('fringe-2025/index')
            ->layout('layout')
            ->with([ 'reviews' => $reviews ])
            ->cascadeContent($page);
    }

}
