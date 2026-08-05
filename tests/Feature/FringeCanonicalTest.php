<?php

namespace Tests\Feature;

use App\Fringe\FestivalUrls;
use Tests\TestCase;

/**
 * The current festival's reviews must exist at exactly one URL, /fringe/reviews, with the
 * matching year URL redirecting there rather than serving a second copy. Get this wrong and
 * nothing visibly breaks: the pages still render, they just split their ranking signals or
 * quietly stop being indexed, and you find out in Search Console weeks later.
 */
class FringeCanonicalTest extends TestCase
{
    private function canonical(string $html): ?string
    {
        preg_match('/<link rel="canonical" href="([^"]+)"/', $html, $m);

        return $m[1] ?? null;
    }

    private function title(string $html): ?string
    {
        preg_match('/<title>(.*?)<\/title>/s', $html, $m);

        return isset($m[1]) ? trim(preg_replace('/\s+/', ' ', $m[1])) : null;
    }

    /**
     * The legacy year-index URLs point at the evergreen page, not at their own year.
     *
     * These carry the site's accumulated head-term authority — before the restructure they
     * were the only "Troy's Fringe reviews" URL there was to link to — and Search Console
     * showed 81 of /fringe-2025/reviews' 85 July 2026 impressions coming from undated
     * queries. Pointing them back at a year archive would spend that on a page that answers
     * an undated question with a stale year.
     */
    public function test_legacy_year_index_urls_go_to_the_evergreen_page(): void
    {
        foreach (['/fringe-2025/reviews', '/fringe-2024/reviews', '/fringe-2025', '/fringe-2025/fringe-2025-reviews'] as $legacy) {
            $this->get($legacy)
                ->assertRedirect(FestivalUrls::EVERGREEN)
                ->assertStatus(301);
        }
    }

    /**
     * A legacy *review* URL is about one show, so it still goes to that show — only the
     * index URLs were retargeted.
     */
    public function test_legacy_review_urls_still_go_to_their_own_show(): void
    {
        $this->get('/fringe-reviews/2025/edmontask')
            ->assertRedirect('/fringe/2025/reviews/edmontask')
            ->assertStatus(301);
    }

    public function test_evergreen_url_serves_the_current_festival(): void
    {
        $response = $this->get('/fringe/reviews');

        $response->assertOk();
        $this->assertSame(
            'https://troypavlek.ca/fringe/reviews',
            $this->canonical($response->getContent()),
        );
    }

    /**
     * A redirect, not a canonical. A canonical is a hint Google may overrule; this has to
     * be a directive so the signals actually consolidate on /fringe/reviews.
     */
    public function test_current_year_url_redirects_to_the_evergreen_url(): void
    {
        $current = FestivalUrls::currentSlug();

        $this->get("/fringe/{$current}/reviews")
            ->assertRedirect('/fringe/reviews')
            ->assertStatus(302);
    }

    /**
     * 302 specifically: this URL starts serving its own archive the moment a newer festival
     * term exists, and a cached 301 would strand people on the evergreen page forever.
     */
    public function test_the_current_year_redirect_is_temporary_not_permanent(): void
    {
        $current = FestivalUrls::currentSlug();

        $this->get("/fringe/{$current}/reviews")->assertStatus(302);
    }

    public function test_archived_years_serve_their_own_page_and_are_self_canonical(): void
    {
        // Cross-canonicalizing an archive to the current year would deindex it, and those
        // years are genuinely distinct content that still earns traffic.
        $response = $this->get('/fringe/2025/reviews');

        $response->assertOk();
        $this->assertSame(
            'https://troypavlek.ca/fringe/2025/reviews',
            $this->canonical($response->getContent()),
        );
    }

    /**
     * The year belongs in the tagline, not stamped after the site name. A frozen "(2025)"
     * is what suppressed CTR on unqualified queries; rendering the current year on an
     * evergreen URL matches both query shapes without ever going stale.
     */
    public function test_titles_carry_the_festival_year_in_the_tagline(): void
    {
        $current = FestivalUrls::currentSlug();

        $this->assertSame(
            "Edmonton Fringe Reviews | The best shows at the {$current} Fringe",
            $this->title($this->get('/fringe/reviews')->getContent()),
        );

        $this->assertSame(
            'Edmonton Fringe Reviews | The best shows at the 2025 Fringe',
            $this->title($this->get('/fringe/2025/reviews')->getContent()),
        );
    }

    /**
     * /fringe/{year} never had a page, but it's the obvious thing to type and a 404 is a
     * bad answer to a good guess.
     */
    public function test_bare_year_redirects_permanently_to_that_years_reviews(): void
    {
        $this->get('/fringe/2025')
            ->assertRedirect('/fringe/2025/reviews')
            ->assertStatus(301);

        $current = FestivalUrls::currentSlug();

        $this->get("/fringe/{$current}")
            ->assertRedirect("/fringe/{$current}/reviews")
            ->assertStatus(301);
    }

    public function test_unknown_festival_years_are_404_not_a_redirect_to_a_404(): void
    {
        $this->get('/fringe/1999')->assertNotFound();
        $this->get('/fringe/1999/reviews')->assertNotFound();
    }

    public function test_sitemap_lists_only_urls_that_actually_serve_a_page(): void
    {
        $current = FestivalUrls::currentSlug();

        $sitemap = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('/fringe/reviews</loc>', $sitemap);
        $this->assertStringContainsString('/fringe/2025/reviews</loc>', $sitemap);
        $this->assertStringNotContainsString("/fringe/{$current}/reviews</loc>", $sitemap);
    }

    /**
     * Internal links must point at the destination, not through the redirect. Linking the
     * current year at its redirecting URL is the signal dilution this whole structure exists
     * to avoid.
     */
    public function test_internal_links_point_at_the_evergreen_url_for_the_current_year(): void
    {
        $current = FestivalUrls::currentSlug();

        foreach (['/fringe', '/fringe/2025/reviews'] as $page) {
            $html = $this->get($page)->getContent();

            $this->assertStringContainsString('href="/fringe/reviews"', $html, "{$page} should link the evergreen URL");
            $this->assertStringNotContainsString(
                "href=\"/fringe/{$current}/reviews\"",
                $html,
                "{$page} links the current year through its redirect instead of at /fringe/reviews",
            );
        }
    }
}
