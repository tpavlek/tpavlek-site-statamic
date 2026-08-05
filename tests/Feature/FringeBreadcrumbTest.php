<?php

namespace Tests\Feature;

use App\Fringe\FestivalUrls;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * Show pages are the section's whole search footprint, and until breadcrumbs they linked
 * only sideways to their own year's index — the /fringe hub had no inbound link from any of
 * them. The visible trail and the BreadcrumbList markup are built from one array, so the
 * risk isn't that they disagree, it's that a crumb quietly names a URL that redirects.
 */
class FringeBreadcrumbTest extends TestCase
{
    private function breadcrumbSchema(string $html): ?array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);

        foreach ($m[1] ?? [] as $json) {
            $data = json_decode(trim($json), true);

            if (($data['@type'] ?? null) === 'BreadcrumbList') {
                return $data;
            }
        }

        return null;
    }

    private function aReview(): Entry
    {
        return EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->first(fn (Entry $entry) => $entry->festival?->slug() !== null);
    }

    public function test_the_reviews_index_carries_a_breadcrumb_trail(): void
    {
        $html = $this->get('/fringe/reviews')->assertOk()->getContent();

        $trail = $this->breadcrumbSchema($html);

        $this->assertNotNull($trail, 'The reviews index emitted no BreadcrumbList.');
        $this->assertSame(
            ['https://troypavlek.ca/fringe', 'https://troypavlek.ca/fringe/reviews'],
            array_column($trail['itemListElement'], 'item'),
        );
    }

    public function test_a_show_page_links_up_to_its_year_and_to_the_hub(): void
    {
        $review = $this->aReview();
        $year = $review->festival->slug();

        $html = $this->get($review->url())->assertOk()->getContent();

        $trail = $this->breadcrumbSchema($html);

        $this->assertNotNull($trail, 'A show page emitted no BreadcrumbList.');
        $this->assertSame(
            [
                'https://troypavlek.ca/fringe',
                FestivalUrls::absoluteReviews($year),
                // FestivalUrls::absolute, not the entry's absoluteUrl(): the latter is built
                // from the app host, so a preview box would publish preview URLs into the
                // markup. Canonicals name the production origin for the same reason.
                FestivalUrls::absolute($review->url()),
            ],
            array_column($trail['itemListElement'], 'item'),
        );

        // The visible trail, not just the markup — Google wants the two to agree, and the
        // hub link only helps if a reader can actually follow it.
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);

        // Relative in the markup a reader clicks, absolute only in the JSON-LD — otherwise
        // every crumb on a preview host walks you off to production.
        $this->assertStringContainsString('href="/fringe"', $html);
        $this->assertStringNotContainsString('href="https://troypavlek.ca/fringe"', $html);

        // On a show page the middle rung is a link, so it wears its full name: this is the
        // anchor text ~200 pages point at the reviews index with.
        $this->assertStringContainsString(">Edmonton Fringe Reviews ({$year})</a>", $html);
    }

    /**
     * The whole point of FestivalUrls: the current festival's year URL redirects, so naming
     * it in a crumb would point every show page at a redirect.
     */
    public function test_no_crumb_names_a_url_that_redirects(): void
    {
        $current = FestivalUrls::currentSlug();

        $review = EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->first(fn (Entry $entry) => $entry->festival?->slug() === $current);

        if (! $review) {
            $this->markTestSkipped("No reviews exist for the current festival ({$current}).");
        }

        $trail = $this->breadcrumbSchema($this->get($review->url())->getContent());

        $this->assertNotContains(
            "https://troypavlek.ca/fringe/{$current}/reviews",
            array_column($trail['itemListElement'], 'item'),
        );
    }

    /**
     * The head term is "edmonton fringe reviews". The h1 used to split it around the year.
     */
    public function test_the_index_heading_keeps_the_head_term_intact(): void
    {
        $html = $this->get('/fringe/reviews')->assertOk()->getContent();

        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m);

        $this->assertSame(
            'Edmonton Fringe Reviews ('.FestivalUrls::currentSlug().')',
            trim(preg_replace('/\s+/', ' ', $m[1] ?? '')),
        );
    }

    public function test_a_show_page_shows_when_it_was_reviewed(): void
    {
        $review = $this->aReview();

        $html = $this->get($review->url())->assertOk()->getContent();

        $this->assertStringContainsString('Reviewed <time datetime="', $html);
        $this->assertStringContainsString($review->date()->format('F j, Y'), $html);
    }

    /**
     * lastModified is a filesystem fact, not an editorial one. The venue migration touched
     * every 2024 and 2025 review on one day in July 2026 without changing a word of any of
     * them, and stamping all 56 "Updated" would be a freshness claim that isn't true.
     */
    public function test_archive_reviews_do_not_claim_to_have_been_updated(): void
    {
        $current = FestivalUrls::currentSlug();

        $archived = EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->first(function (Entry $entry) use ($current) {
                $year = $entry->festival?->slug();

                return $year !== null && $year !== $current && $entry->lastModified()?->year >= (int) $current;
            });

        if (! $archived) {
            $this->markTestSkipped('No archive review has been touched since the current festival began.');
        }

        $html = $this->get($archived->url())->assertOk()->getContent();

        $this->assertStringContainsString('Reviewed <time datetime="', $html);
        $this->assertStringNotContainsString('Updated <time datetime="', $html);
    }
}
