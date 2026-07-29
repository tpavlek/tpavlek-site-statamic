<?php

namespace Tests\Feature;

use App\Fringe\FestivalUrls;
use Statamic\Facades\Antlers;
use Tests\TestCase;

/**
 * FestivalUrls is the single source of truth for where a year's reviews live. Canonicals,
 * sitemap entries and every internal link derive from it, so its contract is worth pinning
 * directly rather than only through rendered pages.
 */
class FestivalUrlsTest extends TestCase
{
    public function test_the_current_festival_resolves_to_the_evergreen_url(): void
    {
        $current = FestivalUrls::currentSlug();

        $this->assertSame('/fringe/reviews', FestivalUrls::reviews($current));
        $this->assertSame('/fringe/reviews', FestivalUrls::reviews());
        $this->assertSame(FestivalUrls::EVERGREEN, FestivalUrls::reviews($current));
    }

    public function test_archived_years_resolve_to_their_own_url(): void
    {
        $this->assertSame('/fringe/2025/reviews', FestivalUrls::reviews('2025'));
        $this->assertSame('/fringe/2024/reviews', FestivalUrls::reviews('2024'));
    }

    /**
     * Canonicals must name the production origin even when rendered elsewhere, or a preview
     * host would publish canonicals pointing at itself.
     */
    public function test_absolute_urls_use_the_production_origin(): void
    {
        $this->assertSame('https://troypavlek.ca/fringe/reviews', FestivalUrls::absoluteReviews());
        $this->assertSame('https://troypavlek.ca/fringe/2025/reviews', FestivalUrls::absoluteReviews('2025'));
    }

    /**
     * The whole point of the class: it never hands back a URL that redirects.
     */
    public function test_it_never_returns_a_url_that_redirects(): void
    {
        foreach (['2024', '2025', FestivalUrls::currentSlug()] as $year) {
            $this->get(FestivalUrls::reviews($year))->assertOk();
        }
    }

    public function test_the_antlers_tag_matches_the_class(): void
    {
        $current = FestivalUrls::currentSlug();

        $this->assertSame(
            '/fringe/reviews',
            (string) Antlers::parse('{{ fringe:reviews_url }}'),
        );

        $this->assertSame(
            '/fringe/2025/reviews',
            (string) Antlers::parse('{{ fringe:reviews_url year="2025" }}'),
        );

        // The form the templates actually use: year bound from a loop variable.
        $this->assertSame(
            '/fringe/2024/reviews',
            (string) Antlers::parse('{{ fringe:reviews_url :year="y" }}', ['y' => '2024']),
        );

        $this->assertSame(
            '/fringe/reviews',
            (string) Antlers::parse('{{ fringe:reviews_url :year="y" }}', ['y' => $current]),
        );
    }
}
