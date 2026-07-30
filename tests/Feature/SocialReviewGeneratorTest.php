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
}
