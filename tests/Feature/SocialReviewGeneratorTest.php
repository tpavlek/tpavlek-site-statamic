<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * The public generator, and the guarantee that it and the entry share card are still one
 * piece of software — both render fringe/social-card/page with the same card and controls.
 */
class SocialReviewGeneratorTest extends TestCase
{
    private const URL = '/fringe/social-review-generator';

    private const FRINGE_URL = 'https://reviews.fringetheatre.ca/events/110-wizard/';

    public function test_the_landing_page_offers_both_ways_in(): void
    {
        $response = $this->get(self::URL);

        $response->assertOk();
        $response->assertSee('Paste a link to the review', false);
        $response->assertSee('type it in yourself', false);
        $response->assertSee('Edmonton Journal', false);
        $response->assertSee('12thNight.ca', false);
        // The builder shouldn't be on screen until a source is chosen.
        $response->assertDontSee('Download PNG', false);
    }

    public function test_starting_from_scratch_opens_an_empty_builder(): void
    {
        $response = $this->post(self::URL, ['manual' => 1]);

        $response->assertOk();
        $response->assertSee('Download PNG', false);
        $response->assertSee('Rating out of 5', false);
        $response->assertSee('"quote":""', false);
    }

    public function test_an_unsupported_host_is_refused_without_fetching_it(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $response = $this->from(self::URL)->post(self::URL, ['url' => 'https://example.com/review']);

        $response->assertRedirect(self::URL);
        $response->assertSessionHasErrors('url');
        Http::assertNothingSent();
    }

    /**
     * The official Fringe site puts every review of a show on the show's page, so a link to
     * it can't open the builder directly — the artist picks a review first.
     */
    public function test_a_fringe_reviews_link_asks_which_review_first(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response(file_get_contents(__DIR__.'/../Fixtures/fringe-reviews-event.html')),
            '*' => Http::response('', 404),
        ]);

        $response = $this->post(self::URL, ['url' => self::FRINGE_URL]);

        $response->assertOk();
        $response->assertSee('Which review?', false);
        $response->assertSee('There are 10 reviews', false);
        $response->assertSee('Allison Murray', false);
        $response->assertSee('Christopher Neale', false);
        $response->assertSee('value="2662"', false);
        // Still a step short of the builder.
        $response->assertDontSee('Download PNG', false);
    }

    public function test_picking_one_of_them_opens_the_builder_on_that_review(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response(file_get_contents(__DIR__.'/../Fixtures/fringe-reviews-event.html')),
            '*' => Http::response('', 404),
        ]);

        $response = $this->post(self::URL, ['url' => self::FRINGE_URL, 'review' => '2662']);

        $response->assertOk();
        $response->assertSee('Download PNG', false);
        $response->assertSee('Brian Cheung, Fringe Reviews', false);
        $response->assertSee('Excellent crowd work', false);
        $response->assertDontSee('Which review?', false);
    }

    /** Nothing to choose between, so the step would only be in the way. */
    public function test_a_show_with_one_review_skips_the_chooser(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response($this->eventPageWith([
                ['id' => '11', 'reviewer' => 'Solo Critic', 'text' => 'A single, solitary review of this particular show.'],
            ])),
            '*' => Http::response('', 404),
        ]);

        $response = $this->post(self::URL, ['url' => self::FRINGE_URL]);

        $response->assertOk();
        $response->assertDontSee('Which review?', false);
        $response->assertSee('Download PNG', false);
        $response->assertSee('Solo Critic, Fringe Reviews', false);
    }

    /** A show nobody has reviewed yet still opens the builder, with a warning. */
    public function test_a_show_with_no_reviews_falls_through_to_a_blank_card(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response($this->eventPageWith([])),
            '*' => Http::response('', 404),
        ]);

        $response = $this->post(self::URL, ['url' => self::FRINGE_URL]);

        $response->assertOk();
        $response->assertDontSee('Which review?', false);
        $response->assertSee('find a review on it', false);
        $response->assertSee('Download PNG', false);
    }

    /** @param  array<int, array{id: string, reviewer: string, text: string}>  $reviews */
    private function eventPageWith(array $reviews): string
    {
        $blocks = '';

        foreach ($reviews as $review) {
            $blocks .= '<div class="flex flex-col lg:flex-row gap-4 rounded-2xl p-2"><div>'
                .'<a href="/reviewers/'.$review['id'].'/">'.$review['reviewer'].'</a>'
                .'<svg class="w-6 h-6 fill-yellow-500"></svg>'
                .'<div class="text-xs font-semibold opacity-60">2026 Fringe</div>'
                .'<div class="text-xs font-semibold opacity-60">July 1, 2026, 1:00 p.m.</div>'
                .'<div class="prose"><p>'.$review['text'].'</p></div>'
                .'</div></div>'
                .'<div><a href="/reports/create/?review='.$review['id'].'"></a></div>';
        }

        return '<html lang="en"><head><meta property="og:title" content="A Show - Fringe Reviews"></head>'
            .'<body><div id="reviews">'.$blocks.'</div></body></html>';
    }

    public function test_a_crawled_review_lands_in_the_builder_prefilled(): void
    {
        Http::fake([
            'edmontonjournal.com/*' => Http::response(file_get_contents(__DIR__.'/../Fixtures/edmonton-journal-review.html')),
            '*' => Http::response('', 404),
        ]);

        $response = $this->post(self::URL, [
            'url' => 'https://edmontonjournal.com/entertainment/theatre/fringe-review-motherhood-the-musical-is-an-amazing-performance-brought-down-by-poor-acoustics',
        ]);

        $response->assertOk();
        $response->assertSee('Justin Bell, Edmonton Journal', false);
        $response->assertSee('"starsValue":3', false);
        $response->assertSee('"starsEnabled":true', false);
        $response->assertSee('Download PNG', false);
    }

    public function test_a_source_without_ratings_opens_with_the_star_switch_off(): void
    {
        Http::fake([
            '12thnight.ca/*' => Http::response(file_get_contents(__DIR__.'/../Fixtures/twelfth-night-review.html')),
            '*' => Http::response('', 404),
        ]);

        $response = $this->post(self::URL, ['url' => 'https://12thnight.ca/2025/08/15/the-ticking-explosive-within-bomb-a-fringe-review/']);

        $response->assertOk();
        $response->assertSee('"starsEnabled":false', false);
        $response->assertSee('Liz Nicholls', false);
    }

    public function test_a_failed_crawl_explains_itself_and_still_opens_the_builder(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $response = $this->post(self::URL, [
            'url' => 'https://edmontonjournal.com/entertainment/theatre/fringe-review-whatever',
        ]);

        $response->assertOk();
        $response->assertSee("load that page from Edmonton Journal", false);
        // Dropped into the builder with nothing filled in, per the brief.
        $response->assertSee('Download PNG', false);
        $response->assertSee('"quote":""', false);
    }

    public function test_the_entry_share_card_still_works_after_the_refactor(): void
    {
        $entry = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->value('title') === '100% Wizard');

        $response = $this->get($entry->url().'/share-card');

        $response->assertOk();
        $response->assertSee('Share this review', false);
        $response->assertSee('Download PNG', false);
        // The rating comes from the review here, so it's fixed rather than editable.
        $response->assertSee('"starsFixedText":"★★★★★"', false);
        $response->assertDontSee('Rating out of 5', false);
    }

    public function test_the_generator_is_indexable_and_the_entry_card_is_not(): void
    {
        $this->get(self::URL)->assertDontSee('noindex', false);

        $entry = EntryFacade::query()->where('collection', 'fringe_reviews')->get()->first();
        $this->get($entry->url().'/share-card')->assertSee('noindex', false);
    }

    /**
     * This page has its own shell rather than layout.antlers.html, so it doesn't inherit the
     * site's sharing tags — it went out on Facebook as a bare link until these were added.
     */
    public function test_the_generator_carries_its_own_sharing_tags(): void
    {
        $response = $this->get(self::URL);

        $response->assertSee('property="og:image" content="'.url('/assets/og-social-review-generator.png').'"', false);
        $response->assertSee('property="og:title"', false);
        $response->assertSee('property="og:description"', false);
        $response->assertSee('name="twitter:card" content="summary_large_image"', false);
        $response->assertSee('rel="canonical" href="'.route('fringe.social-review-generator').'"', false);

        $this->assertFileExists(public_path('assets/og-social-review-generator.png'));
        $this->assertSame(
            [1200, 630],
            array_slice(getimagesize(public_path('assets/og-social-review-generator.png')), 0, 2),
            'Facebook wants 1.91:1; anything else gets cropped unpredictably.',
        );
    }

    /** The share card for one of Troy's own reviews is a tool page, not something to share. */
    public function test_the_entry_card_does_not_get_the_sharing_tags(): void
    {
        $entry = EntryFacade::query()->where('collection', 'fringe_reviews')->get()->first();

        $this->get($entry->url().'/share-card')->assertDontSee('og:image', false);
    }
}
