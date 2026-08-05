<?php

namespace Tests\Feature;

use App\Fringe\Artists;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * Artist pages exist to catch "martin dockery fringe" — a query the site had no page for
 * despite holding every review the asker wants.
 *
 * What's worth guarding is the gate. Publishing all 48 artist entries would mean dozens of
 * pages titled with an Instagram handle, each a worse copy of the single review it links to,
 * and thin duplicate pages cost the whole section rather than just themselves.
 */
class FringeArtistPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artists::flush();
    }

    public function test_the_index_lists_only_artists_with_pages(): void
    {
        $html = $this->get('/fringe/artists')->assertOk()->getContent();

        foreach (Artists::withPages() as $artist) {
            $this->assertStringContainsString(Artists::url($artist), $html);
            $this->assertStringContainsString((string) $artist->value('title'), $html);
        }
    }

    public function test_an_artist_page_lists_every_show_they_have_brought(): void
    {
        $artist = Artists::withPages()->first();

        $this->assertNotNull($artist, 'No artist qualifies for a page.');

        $html = $this->get(Artists::url($artist))->assertOk()->getContent();

        foreach (Artists::reviews($artist) as $review) {
            $this->assertStringContainsString($review->url(), $html);
        }
    }

    /**
     * A returning show is one show. Listing Field Zoology 301 twice because it ran in 2024
     * and again in 2026 makes a company look like it has twice the output it does.
     */
    public function test_a_returning_show_is_listed_once(): void
    {
        foreach (Artists::withPages() as $artist) {
            $titles = Artists::works($artist)
                ->map(fn (EntryContract $work) => (string) $work->value('title'))
                ->all();

            $this->assertSame(
                array_values(array_unique($titles)),
                $titles,
                "{$artist->value('title')} lists a show more than once.",
            );
        }
    }

    /**
     * Collapsing must not lose the earlier reviews — each is a real page, and this is the
     * only route into the older ones from here.
     */
    public function test_every_staging_is_still_linked(): void
    {
        $returning = Artists::withPages()
            ->first(fn (EntryContract $a) => Artists::works($a)->count() < Artists::reviews($a)->count());

        $this->assertNotNull($returning, 'No artist has a returning show to collapse.');

        $html = $this->get(Artists::url($returning))->assertOk()->getContent();

        foreach (Artists::reviews($returning) as $review) {
            $this->assertStringContainsString(
                'href="'.$review->url().'"',
                $html,
                "{$review->value('title')} lost its link when its show was collapsed.",
            );
        }
    }

    /**
     * The two grouping rules, on the data that motivated each.
     *
     * Weird Al Karaoke is joined by original_review across a changing subtitle; Late Night
     * Cabaret is three identically-titled entries with only one link between them, because
     * the original_review sweep only ran over the 2026 shows.
     */
    public function test_stagings_group_by_link_and_by_identical_title(): void
    {
        foreach (['weird-al-karaoke', 'late-night-cabaret'] as $slug) {
            $artist = Artists::find($slug);

            if (! $artist) {
                continue;
            }

            $this->assertGreaterThan(1, Artists::reviews($artist)->count());
            $this->assertCount(1, Artists::works($artist), "{$slug} did not collapse to one show.");
        }
    }

    /**
     * Sketchy Broads have brought "Choosing the Bear", "Easy Bake Coven" and "Resting
     * Bitumen Face" — three different shows sharing a title prefix. Any looser title rule
     * than exact-match would merge them into one.
     */
    public function test_shows_sharing_a_title_prefix_are_not_merged(): void
    {
        $artist = Artists::find('sketchy-broads');

        if (! $artist) {
            $this->markTestSkipped('Sketchy Broads has no page.');
        }

        $this->assertCount(3, Artists::works($artist));
    }

    /**
     * The ticket importer names new artists after their Instagram handle. A page titled
     * "weirdalkaraokeyeg" ranks for nothing and reads as a bug.
     */
    public function test_an_artist_still_named_after_their_handle_has_no_page(): void
    {
        $unnamed = EntryFacade::query()
            ->where('collection', 'artists')
            ->get()
            ->first(fn (EntryContract $artist) => ! Artists::hasRealName($artist));

        if (! $unnamed) {
            $this->markTestSkipped('Every artist has a real name.');
        }

        $this->get('/fringe/artists/'.$unnamed->slug())->assertNotFound();
        $this->get('/fringe/artists/'.Artists::slug($unnamed))->assertNotFound();
    }

    /**
     * One show is a worse copy of the review it links to.
     */
    public function test_an_artist_with_a_single_show_has_no_page(): void
    {
        $single = EntryFacade::query()
            ->where('collection', 'artists')
            ->get()
            ->first(fn (EntryContract $artist) => Artists::hasRealName($artist)
                && Artists::reviews($artist)->count() === 1);

        if (! $single) {
            $this->markTestSkipped('Every named artist has more than one show.');
        }

        $this->get('/fringe/artists/'.Artists::slug($single))->assertNotFound();
    }

    /**
     * URLs come from the artist's name, never their Instagram handle.
     *
     * The stored slugs were all handles once — "martindockery1", "satco", "sasquatchphd" —
     * and were rewritten from the names on 2026-08-05. The rule still has to hold, because
     * the ticket importer mints a fresh handle-slug for every artist it creates, so any
     * newly imported company arrives in exactly the old shape.
     *
     * Tested against the handle rather than the stored slug: those now agree for every
     * existing artist, so a test that looked for a difference between them would quietly
     * skip and guard nothing.
     */
    public function test_urls_are_built_from_the_artist_name_not_the_instagram_handle(): void
    {
        $artist = Artists::withPages()
            ->first(fn (EntryContract $a) => $a->value('instagram')
                && Artists::slug($a) !== Str::slug((string) $a->value('instagram')));

        $this->assertNotNull($artist, 'No artist has a name that differs from their handle.');

        $this->get(Artists::url($artist))->assertOk();

        // The handle-derived URL is not a second address for the same page.
        $this->get('/fringe/artists/'.Str::slug((string) $artist->value('instagram')))
            ->assertNotFound();
    }

    /**
     * Names were researched and typed in; a handle left as a title means one was missed.
     * Not an assertion that every artist is named — most aren't, deliberately — but every
     * artist *with a page* must be, since the name is the page's whole reason to exist.
     */
    public function test_no_artist_page_is_titled_with_a_handle(): void
    {
        foreach (Artists::withPages() as $artist) {
            $this->assertTrue(
                Artists::hasRealName($artist),
                "{$artist->slug()} has a page but is still titled with its Instagram handle.",
            );
        }
    }

    public function test_an_artist_page_is_canonical_to_itself_and_carries_breadcrumbs(): void
    {
        $artist = Artists::withPages()->first();
        $html = $this->get(Artists::url($artist))->assertOk()->getContent();

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://troypavlek.ca'.Artists::url($artist).'"',
            $html,
        );
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringContainsString('href="/fringe/artists"', $html);
    }

    /**
     * Both JSON-LD blocks have to be valid on their own — they're read independently.
     */
    public function test_the_structured_data_parses(): void
    {
        $artist = Artists::withPages()->first();
        $html = $this->get(Artists::url($artist))->assertOk()->getContent();

        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);

        $types = [];

        foreach ($matches[1] as $json) {
            $data = json_decode(trim($json), true);
            $this->assertIsArray($data, 'A JSON-LD block did not parse.');
            $types[] = $data['@type'];
        }

        $this->assertContains('CollectionPage', $types);
        $this->assertContains('BreadcrumbList', $types);
    }

    /**
     * A review by an artist with a page should link to it — that link is the whole reason
     * the page accumulates any authority.
     */
    public function test_a_review_links_to_its_artist_page(): void
    {
        $artist = Artists::withPages()->first();
        $review = Artists::reviews($artist)->first();

        $html = $this->get($review->url())->assertOk()->getContent();

        $this->assertStringContainsString('href="'.Artists::url($artist).'"', $html);
    }
}
