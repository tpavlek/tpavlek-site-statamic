<?php

namespace App\Http\Controllers;

use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Taxonomy;
use Statamic\Taxonomies\LocalizedTerm;

class EndorsementsController
{

    public function currentYear()
    {
        $page = EntryFacade::query()
            ->where('collection', 'pages')
            ->where('slug', 'endorsements')
            ->first();

        $videos = EntryFacade::query()
            ->where('collection', 'videos')
            ->get()
            ->filter(function (Entry $entry) {
                return $entry->category->contains(fn (LocalizedTerm $term) => $term->slug === 'yegvote-2025');
            });

        return (new \Statamic\View\View)
            ->template('yegvote-2025/endorsements')
            ->layout('layout')
            ->with([ 'videos' => $videos ])
            ->cascadeContent($page);
    }

}
