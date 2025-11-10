<?php

namespace App\Http\Controllers;

use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Taxonomies\LocalizedTerm;

class YegvoteController
{

    public function yegvote_2025()
    {
        $page = EntryFacade::query()
            ->where('collection', 'pages')
            ->where('slug', 'yegvote-2025')
            ->first();

        $videos = EntryFacade::query()
            ->where('collection', 'videos')
            ->get()
            ->filter(function (Entry $entry) {
                return $entry->category->contains(fn (LocalizedTerm $term) => $term->slug === 'yegvote-2025');
            });

        return (new \Statamic\View\View)
            ->template('yegvote-2025/index')
            ->layout('layout')
            ->with([ 'videos' => $videos ])
            ->cascadeContent($page);
    }

}
