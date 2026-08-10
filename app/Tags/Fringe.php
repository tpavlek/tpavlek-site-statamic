<?php

namespace App\Tags;

use App\Fringe\FestivalUrls;
use App\Fringe\ShowAvailability;
use App\Fringe\TicketPage;
use Statamic\Tags\Tags;

/**
 * Antlers access to App\Fringe\FestivalUrls, so templates never hand-roll the
 * "is this the current festival?" branch.
 *
 *     {{ fringe:reviews_url }}                      the current festival
 *     {{ fringe:reviews_url year="2025" }}          a literal year
 *     {{ fringe:reviews_url :year="slug" }}         a year from a variable
 *     {{ fringe:current_year }}                     e.g. 2026
 *     {{ fringe:availability }} … {{ /fringe:availability }}   the current review's showtimes
 */
class Fringe extends Tags
{
    /**
     * Ticket availability for the review being rendered, as a scope for the review page's
     * "Performance & Availability" card. Renders its contents only when we have scraped data
     * for the show; otherwise the pair is empty and the card never appears.
     *
     * The auth gate is applied here, server-side: a logged-out request gets `reveal_numbers`
     * false and ShowAvailability has already stripped every seat count and percentage, so the
     * private figures never reach the template.
     *
     * @return array<string, mixed>|false
     */
    public function availability(): array|false
    {
        $eventId = TicketPage::eventId((string) $this->context->get('ticket_link'));
        $reveal = auth()->check();

        $data = ShowAvailability::forEventId($eventId, $reveal);

        return $data ? [
            'reveal_numbers' => $reveal,
            'event_id' => $eventId,
            // Running time, from the review entry's own duration field — public, like capacity.
            'duration_minutes' => (int) $this->context->value('duration') ?: null,
            ...$data,
        ] : false;
    }

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
