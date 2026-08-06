<?php

namespace Tests\Feature;

use App\Fringe\FestivalUrls;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * The end-of-post prompt sending Fringe readers to the reviews.
 *
 * It has to name the festival currently on and link the evergreen URL, not the year the post
 * was written about — the whole point is that an August reader landing on a two-year-old
 * Fringe post gets pointed at this year's reviews without anyone editing old posts. A
 * hardcoded year would go stale in exactly the way the evergreen URL work exists to prevent.
 */
class FringePostCtaTest extends TestCase
{
    private function aPost(bool $fringe): ?EntryContract
    {
        return EntryFacade::query()
            ->where('collection', 'posts')
            ->where('published', true)
            ->get()
            ->first(function (EntryContract $entry) use ($fringe) {
                $topics = $entry->topics?->map->slug()->all() ?? [];

                return in_array('fringe', $topics, true) === $fringe;
            });
    }

    public function test_a_fringe_post_offers_the_current_reviews(): void
    {
        $post = $this->aPost(fringe: true);

        $this->assertNotNull($post, 'No published post is tagged fringe.');

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertStringContainsString(
            'Looking for reviews of shows at Edmonton Fringe '.FestivalUrls::currentSlug().'?',
            $html,
        );

        // The evergreen URL, never the year-specific one, which redirects.
        $this->assertStringContainsString('href="'.FestivalUrls::EVERGREEN.'"', $html);
    }

    public function test_a_post_that_is_not_about_the_fringe_does_not(): void
    {
        $post = $this->aPost(fringe: false);

        if (! $post) {
            $this->markTestSkipped('Every published post is tagged fringe.');
        }

        $this->assertStringNotContainsString(
            'Looking for reviews of shows',
            $this->get($post->url())->assertOk()->getContent(),
        );
    }
}
