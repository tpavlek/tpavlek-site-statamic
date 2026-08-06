<?php

namespace App\Tags;

use App\Fringe\FestivalUrls;
use Statamic\Tags\Tags;

/**
 * Antlers access to App\Fringe\FestivalUrls, so templates never hand-roll the
 * "is this the current festival?" branch.
 *
 *     {{ fringe:reviews_url }}                      the current festival
 *     {{ fringe:reviews_url year="2025" }}          a literal year
 *     {{ fringe:reviews_url :year="slug" }}         a year from a variable
 *     {{ fringe:current_year }}                     e.g. 2026
 */
class Fringe extends Tags
{
    public function reviewsUrl(): string
    {
        return FestivalUrls::reviews($this->params->get('year'));
    }

    /**
     * The festival currently on. For copy that has to name a year without going stale —
     * creating next year's fringe_festival term is what moves it.
     */
    public function currentYear(): string
    {
        return FestivalUrls::currentSlug();
    }
}
