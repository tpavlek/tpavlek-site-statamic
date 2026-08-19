<?php

namespace App\Tags;

use App\Fringe\AgendaCalendar;
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

    /**
     * When Troy's calendar has this show booked — for the watchlist card on a review page.
     * Matches the current review against the agenda calendar the same two ways the agenda
     * page does (ticket-site event id, then normalized-title containment), restricted to the
     * current festival year. Prefers the next upcoming booking; if the only match already
     * happened, says so via `upcoming` false so the copy can shift to "review on the way".
     *
     * Renders nothing when the show isn't on the calendar at all.
     *
     * @return array<string, mixed>|false
     */
    public function agendaSlot(): array|false
    {
        $events = AgendaCalendar::eventsForShow(
            TicketPage::eventId((string) $this->context->get('ticket_link')),
            (string) $this->context->get('title'),
        )->filter(fn (array $event) => (string) $event['starts']->year === FestivalUrls::currentSlug());

        $slot = $events->first(fn (array $event) => $event['starts']->isFuture())
            ?? $events->last();

        return $slot ? [
            'upcoming' => $slot['starts']->isFuture(),
            'slot_display' => $slot['starts']->format('l, F j \a\t g:i A'),
            'slot_iso' => $slot['starts']->toIso8601String(),
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
