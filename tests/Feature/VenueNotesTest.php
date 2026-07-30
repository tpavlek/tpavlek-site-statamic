<?php

namespace Tests\Feature;

use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Stache;
use Tests\TestCase;

/**
 * Venue notes are written once on the venue and appended to every review of a show playing
 * there. The venue's number is stored apart from its name so a renumbering doesn't strand the
 * notes, which means every display of a venue has to join the two back together.
 */
class VenueNotesTest extends TestCase
{
    private function venue(string $slug)
    {
        return EntryFacade::query()->where('collection', 'venues')->get()
            ->first(fn ($e) => $e->slug() === $slug);
    }

    private function reviewsAt(string $venueId)
    {
        return EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->filter(function ($e) use ($venueId) {
                $v = $e->value('venue');

                return (is_array($v) ? ($v[0] ?? null) : $v) === $venueId;
            });
    }

    public function test_venue_displays_with_its_number(): void
    {
        $venue = $this->venue('strathcona-high-school');

        $this->assertSame('29: Strathcona High School', (string) $venue->augmentedValue('display_name')->value());
    }

    public function test_notes_appear_on_every_review_at_that_venue_and_nowhere_else(): void
    {
        $venue = $this->venue('sea-change-granite-club');
        $marker = 'Sightlines are fine but the bar queue is not.';

        $venue->set('notes', $marker)->save();
        Stache::clear();

        try {
            $at = $this->reviewsAt($venue->id());

            $this->assertGreaterThan(1, $at->count(), 'Expected more than one show at this venue.');

            foreach ($at as $review) {
                $response = $this->get($review->url());
                $response->assertOk();
                $response->assertSee($marker, false);
                $response->assertSee('Venue notes', false);
            }

            // A show at a different venue must not pick them up.
            $elsewhere = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
                ->first(fn ($e) => str_contains($e->slug(), 'bat-boy'));

            $this->get($elsewhere->url())->assertDontSee($marker, false);
        } finally {
            $this->venue('sea-change-granite-club')->remove('notes')->save();
            Stache::clear();
        }
    }

    public function test_a_venue_without_notes_renders_no_panel(): void
    {
        $venue = $this->venue('sea-change-granite-club');

        $this->assertNull($venue->value('notes'), 'Fixture leaked: this venue should have no notes.');

        $review = $this->reviewsAt($venue->id())->first();

        $this->get($review->url())->assertDontSee('Venue notes', false);
    }

    public function test_schema_location_uses_the_numbered_venue_name(): void
    {
        $review = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => str_contains($e->slug(), 'bat-boy'));

        $schema = json_decode(\App\Schema\FringeReviewSchema::build($review), true);

        $event = $schema['@type'] === 'TheaterEvent' ? $schema : $schema['itemReviewed'];

        $this->assertSame('29: Strathcona High School', $event['location']['name']);
    }
}
