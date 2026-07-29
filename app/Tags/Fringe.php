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
 */
class Fringe extends Tags
{
    public function reviewsUrl(): string
    {
        return FestivalUrls::reviews($this->params->get('year'));
    }
}
