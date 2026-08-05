<?php

namespace Tests\Feature;

use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * The per-review share-card page must stay out of the index, and must never claim to be the
 * review.
 *
 * It got indexed anyway: in August 2026 Search Console reported
 * /fringe/2026/reviews/pork-beans-attorneys-at-law/share-card as "Submitted and indexed" with
 * indexingState INDEXING_ALLOWED, and it outranked the review itself for the show's name.
 * Google had last crawled it the day the meta tag shipped and evidently didn't have it yet.
 *
 * Two directives now, meta and header, for the reason OgCardController already has both — a
 * header can't be missed by a parser, and it covers responses that never get parsed as HTML.
 *
 * The canonical assertion is the important one. Pointing this page's canonical at the review
 * is the obvious-looking fix and is wrong twice over: it isn't a duplicate of the review
 * (it's a tool that happens to quote it), and Google's guidance is not to combine noindex
 * with a canonical, because the conflicting signals can carry the noindex to the target —
 * which here is the review page that has to stay indexed.
 */
class ShareCardIndexingTest extends TestCase
{
    private function aReview(): Entry
    {
        return EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->first(fn (Entry $entry) => $entry->festival?->slug() !== null);
    }

    public function test_the_share_card_page_is_noindex_by_meta_and_header(): void
    {
        $response = $this->get($this->aReview()->url().'/share-card')->assertOk();

        $this->assertStringContainsString(
            '<meta name="robots" content="noindex">',
            $response->getContent(),
        );

        $this->assertStringContainsString(
            'noindex',
            (string) $response->headers->get('X-Robots-Tag'),
            'The share-card response is missing its X-Robots-Tag.',
        );
    }

    /**
     * Never a canonical on a noindexed page — see the class comment.
     */
    public function test_the_share_card_page_does_not_canonicalise_to_the_review(): void
    {
        $review = $this->aReview();

        $this->assertStringNotContainsString(
            '<link rel="canonical"',
            $this->get($review->url().'/share-card')->assertOk()->getContent(),
        );
    }

    /**
     * The review itself stays indexable and self-canonical — it's the page that should win
     * the show's name.
     */
    public function test_the_review_itself_stays_indexable(): void
    {
        $review = $this->aReview();
        $response = $this->get($review->url())->assertOk();

        $this->assertStringNotContainsString('content="noindex"', $response->getContent());
        $this->assertNull($response->headers->get('X-Robots-Tag'));
    }

    /**
     * A noindexed utility page has no business in the sitemap either.
     */
    public function test_share_cards_are_not_in_the_sitemap(): void
    {
        $this->assertStringNotContainsString(
            'share-card',
            $this->get('/sitemap.xml')->assertOk()->getContent(),
        );
    }
}
