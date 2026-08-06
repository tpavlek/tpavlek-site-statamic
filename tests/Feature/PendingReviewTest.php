<?php

namespace Tests\Feature;

use App\Fringe\Artists;
use App\Fringe\FestivalUrls;
use App\Fringe\Reviews;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * An imported-but-unreviewed show must not reach the public site anywhere.
 *
 * The lineup import puts every show at the festival into fringe_reviews as a draft with
 * `recommendation: pending`. Statamic 404s the draft's URL and the sitemap generator skips
 * it, but `Entry::query()` includes drafts by default — so every listing had to be moved onto
 * App\Fringe\Reviews::published(), and the cost of missing one is silent: 190 extra rows on
 * the reviews index, contentless items in the feed, artist pages linking to 404s, or the
 * index's JSON-LD handing Google an ItemList of dead URLs.
 *
 * This creates a real pending entry, checks every surface, and deletes it again. The
 * teardown runs even when an assertion fails, because a leaked fixture in this collection is
 * a review that shows up on the live site.
 */
class PendingReviewTest extends TestCase
{
    private const SLUG = 'zzz-pending-fixture-delete-me';

    private ?EntryContract $pending = null;

    protected function tearDown(): void
    {
        $this->deleteFixture();

        parent::tearDown();
    }

    private function deleteFixture(): void
    {
        EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->filter(fn (EntryContract $entry) => $entry->slug() === self::SLUG)
            ->each->delete();

        $this->pending = null;

        Artists::flush();
    }

    /**
     * A pending import: draft, `pending`, no review text — and attached to an artist who
     * already has a page, so the artist surfaces get exercised too.
     */
    private function makePending(): EntryContract
    {
        $artist = Artists::withPages()->first();
        $year = FestivalUrls::currentSlug();

        $this->pending = EntryFacade::make()
            ->collection('fringe_reviews')
            ->slug(self::SLUG)
            ->date($year.'-08-14')
            ->published(false)
            ->data([
                'title' => 'ZZZ Pending Fixture',
                'festival' => $year,
                'recommendation' => 'pending',
                'artist' => $artist?->id(),
            ]);

        $this->pending->save();

        Artists::flush();

        return $this->pending;
    }

    public function test_a_pending_import_has_no_page_of_its_own(): void
    {
        $entry = $this->makePending();

        $this->assertSame('draft', $entry->status());
        $this->get($entry->url())->assertNotFound();
    }

    public function test_a_pending_import_stays_off_the_reviews_index(): void
    {
        $before = $this->get(FestivalUrls::EVERGREEN)->assertOk()->getContent();
        $beforeCount = substr_count($before, '<li class="relative py-3');

        $entry = $this->makePending();

        $after = $this->get(FestivalUrls::EVERGREEN)->assertOk()->getContent();

        $this->assertStringNotContainsString('ZZZ Pending Fixture', $after);
        $this->assertStringNotContainsString($entry->url(), $after);
        $this->assertSame(
            $beforeCount,
            substr_count($after, '<li class="relative py-3'),
            'A pending import added a row to the reviews index.',
        );
    }

    /**
     * The worst of the leaks: structured data naming a URL that 404s.
     */
    public function test_a_pending_import_stays_out_of_the_index_structured_data(): void
    {
        $entry = $this->makePending();

        $html = $this->get(FestivalUrls::EVERGREEN)->assertOk()->getContent();

        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);

        foreach ($matches[1] as $json) {
            $this->assertStringNotContainsString(
                $entry->url(),
                (string) $json,
                'A pending import reached the JSON-LD.',
            );
        }
    }

    public function test_a_pending_import_stays_out_of_the_feed_and_sitemap(): void
    {
        $entry = $this->makePending();

        $this->assertStringNotContainsString(
            $entry->url(),
            $this->get('/fringe/reviews/feed.xml')->assertOk()->getContent(),
        );

        $this->assertStringNotContainsString(
            $entry->url(),
            $this->get('/sitemap.xml')->assertOk()->getContent(),
        );
    }

    /**
     * The gate counts published reviews, so an import must neither appear on an artist's
     * page nor help an artist earn one.
     */
    public function test_a_pending_import_does_not_reach_artist_pages(): void
    {
        $artist = Artists::withPages()->first();

        $this->assertNotNull($artist, 'No artist has a page.');

        $worksBefore = Artists::works($artist)->count();

        $entry = $this->makePending();

        $this->assertSame(
            $worksBefore,
            Artists::works($artist)->count(),
            'A pending import was counted as one of the artist\'s shows.',
        );

        $html = $this->get(Artists::url($artist))->assertOk()->getContent();

        $this->assertStringNotContainsString('ZZZ Pending Fixture', $html);
        $this->assertStringNotContainsString($entry->url(), $html);
    }

    /**
     * The other half: the lineup table has to be able to find these, and must not pick up a
     * review Troy is part-way through writing — which is also a draft.
     */
    public function test_pending_imports_are_findable_and_half_written_drafts_are_not(): void
    {
        $entry = $this->makePending();

        $this->assertTrue(
            Reviews::pending()->contains(fn (EntryContract $e) => $e->id() === $entry->id()),
            'Reviews::pending() did not find the import, so the lineup table would be empty.',
        );

        $this->assertFalse(
            Reviews::published()->contains(fn (EntryContract $e) => $e->id() === $entry->id()),
        );

        // Same entry, now a review in progress rather than an untouched import.
        $entry->set('recommendation', 'watchlist')->save();

        $this->assertFalse(
            Reviews::pending()->contains(fn (EntryContract $e) => $e->id() === $entry->id()),
            'A half-written draft would show up in the public lineup table.',
        );
    }

    /**
     * Every real review must still be published — if the import ever flips an existing
     * review to draft, its page disappears and nothing else says so.
     */
    public function test_no_existing_review_is_a_pending_draft(): void
    {
        $wrong = Reviews::all()
            ->filter(fn (EntryContract $entry) => ! $entry->published() && ! Reviews::isPending($entry))
            ->map(fn (EntryContract $entry) => $entry->slug())
            ->all();

        $this->assertSame([], array_values($wrong), implode("\n", [
            'These entries are drafts but not marked pending, so they have no page and are',
            'not in the lineup either — they are invisible:',
            '  '.implode("\n  ", $wrong),
        ]));
    }
}
