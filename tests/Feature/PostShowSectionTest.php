<?php

namespace Tests\Feature;

use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * The `show` set: a section of a post about one show, rendered as a real heading, plus the
 * schema.org ItemList that a post built out of them earns automatically.
 *
 * The failures these guard against are all silent — the page still renders, it just stops
 * being navigable, stops being honest about which year a rating is from, or stops carrying
 * the markup that gets the round-up into a search result.
 */
class PostShowSectionTest extends TestCase
{
    private const SLUG = 'show-section-test-post';

    private function cleanup(): void
    {
        EntryFacade::query()->where('collection', 'posts')->get()
            ->first(fn ($e) => $e->slug() === self::SLUG)?->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    /**
     * Shows return under the same name year after year, so a title alone is ambiguous —
     * "Field Zoology 301" is both the 2024 original and the 2026 restaging, and picking the
     * wrong one quietly inverts what a test is asserting.
     */
    private function review(string $title, string $festival)
    {
        $review = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->value('title') === $title && $e->value('festival') === $festival);

        $this->assertNotNull($review, "No fringe_reviews entry titled {$title} at Fringe {$festival}.");

        return $review;
    }

    private function makePost(array $sets)
    {
        $this->cleanup();

        $post = EntryFacade::make()
            ->collection('posts')
            ->slug(self::SLUG)
            ->date('2026-07-30')
            ->published(true)
            ->data([
                'title' => 'Show section test',
                'og_description' => 'Six shows, one test.',
                'content' => array_map(fn ($values, $i) => [
                    'type' => 'set',
                    'attrs' => ['id' => 'showset'.$i, 'values' => ['type' => 'show'] + $values],
                ], $sets, array_keys($sets)),
            ]);

        $post->save();

        return $post;
    }

    public function test_the_posts_bard_field_offers_the_show_set(): void
    {
        $sets = CollectionFacade::findByHandle('posts')->entryBlueprint()->field('content')->get('sets');

        $show = $sets['new_set_group']['sets']['show'] ?? null;

        $this->assertNotNull($show, 'The posts bard field lost its `show` set.');

        $handles = collect($show['fields'])->pluck('handle')->all();

        $this->assertContains('review', $handles);
        $this->assertContains('previous_reviews', $handles);
    }

    /**
     * The whole reason the set exists rather than a pin. A pin is an inline Bard node, so it
     * can only ever render inside a paragraph; a six-show round-up with no headings is one
     * neither a screen reader nor a skimming reader can navigate.
     */
    public function test_a_show_section_renders_a_real_heading(): void
    {
        $review = $this->review('Field Zoology 301', '2026');
        $post = $this->makePost([['review' => $review->id(), 'level' => 'h2']]);

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<h2[^>]*>\s*(<!--.*?-->\s*)?<a href="'.preg_quote($review->url(), '~').'"[^>]*>Field Zoology 301 ~s',
            $html,
            'The show section did not render its title as a linked heading.'
        );
    }

    /**
     * The year in the heading is the festival this staging plays, not the year any inherited
     * rating was earned — that one is on the returning badge, and conflating the two is the
     * mistake this whole area exists to avoid.
     */
    public function test_the_heading_carries_the_year_the_show_plays(): void
    {
        $review = $this->review('Field Zoology 301', '2026');
        $post = $this->makePost([['review' => $review->id(), 'level' => 'h2']]);

        $html = $this->get($post->url())->assertOk()->getContent();

        preg_match('~<h2[^>]*>.*?</h2>~s', $html, $heading);

        $this->assertStringContainsString('(2026)', $heading[0]);
        $this->assertStringContainsString('Returning &middot; 2024', $heading[0]);
    }

    /**
     * A returning show wears its predecessor's stars, and the year those were earned. Both
     * halves matter: Troy stands behind the old rating, but stamping it with this festival's
     * year would claim a verdict on a staging nobody has seen yet.
     */
    public function test_an_inherited_rating_is_labelled_with_the_year_it_was_earned(): void
    {
        $review = $this->review('Field Zoology 301', '2026');

        $this->assertSame('', (string) $review->value('stars'), 'Expected an unrated returning show.');

        $original = EntryFacade::find($review->value('original_review'));
        $originalYear = $original->value('festival');

        $this->assertNotSame($originalYear, $review->value('festival'), 'Expected the original to be a different year.');

        $post = $this->makePost([['review' => $review->id(), 'level' => 'h2']]);
        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertStringContainsString('Returning &middot; '.$originalYear, $html);
        $this->assertStringNotContainsString('Returning &middot; '.$review->value('festival'), $html);
    }

    /** Stars are glyphs. Without the spoken equivalent a screen reader gets nothing at all. */
    public function test_a_rating_in_a_heading_carries_a_text_alternative(): void
    {
        $post = $this->makePost([['review' => $this->review('Field Zoology 301', '2026')->id(), 'level' => 'h2']]);

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertMatchesRegularExpression('~<span class="sr-only">[\d.]+ out of 5 stars</span>~', $html);
    }

    /**
     * The default path: nothing picked, and the company's earlier shows are found from the
     * artist. This is the answer to "scope the picker to the same artist" — if the list is
     * derivable there is nothing to pick, which is just as well, because a relationship field
     * cannot see a sibling field's value when its CP metadata is built.
     */
    public function test_earlier_shows_by_the_same_company_are_listed_without_being_picked(): void
    {
        $post = $this->makePost([[
            'review' => $this->review('Sketchy Broads presents: Resting Bitumen Face', '2026')->id(),
            'level' => 'h2',
        ]]);

        $response = $this->get($post->url())->assertOk();

        $response->assertSee('Previously:', false);
        $response->assertSee($this->review('Sketchy Broads: Easy Bake Coven', '2025')->url(), false);
        $response->assertSee($this->review('Sketchy Broads: Choosing the Bear', '2024')->url(), false);
    }

    /** Newest first, and never a show from the same festival — that one is not "previous". */
    public function test_derived_shows_run_newest_first_and_exclude_the_current_festival(): void
    {
        $post = $this->makePost([[
            'review' => $this->review('Sketchy Broads presents: Resting Bitumen Face', '2026')->id(),
            'level' => 'h2',
        ]]);

        $html = $this->get($post->url())->assertOk()->getContent();

        $coven = strpos($html, $this->review('Sketchy Broads: Easy Bake Coven', '2025')->url());
        $bear = strpos($html, $this->review('Sketchy Broads: Choosing the Bear', '2024')->url());

        $this->assertLessThan($bear, $coven, 'Earlier shows should run newest first.');
        $this->assertStringNotContainsString('(2026)</span></a>,', $html);
    }

    /**
     * A restaging under the same name is already announced by the returning badge; listing it
     * again as "Previously" states the same fact a third time in three lines.
     */
    public function test_a_restaging_under_the_same_name_is_not_repeated(): void
    {
        $review = $this->review('Field Zoology 301', '2026');
        $post = $this->makePost([['review' => $review->id(), 'level' => 'h2']]);

        $response = $this->get($post->url())->assertOk();

        $response->assertSee('Returning &middot; 2024', false);
        $response->assertDontSee('Previously:', false);
    }

    /** Picking explicitly still wins, for a subset or a company that changed names. */
    public function test_previous_stagings_are_listed_with_their_own_years(): void
    {
        $bear = $this->review('Sketchy Broads: Choosing the Bear', '2024');
        $coven = $this->review('Sketchy Broads: Easy Bake Coven', '2025');

        $post = $this->makePost([[
            'review' => $this->review('Sketchy Broads presents: Resting Bitumen Face', '2026')->id(),
            'previous_reviews' => [$bear->id(), $coven->id()],
            'level' => 'h2',
        ]]);

        $response = $this->get($post->url())->assertOk();

        $response->assertSee('Previously:', false);
        $response->assertSee($bear->url(), false);
        $response->assertSee($coven->url(), false);
        $response->assertSee('('.$bear->value('festival').')', false);
        $response->assertSee('('.$coven->value('festival').')', false);
    }

    public function test_a_post_of_show_sections_publishes_an_item_list(): void
    {
        $one = $this->review('Field Zoology 301', '2026');
        $two = $this->review('110% Wizard', '2026');

        $post = $this->makePost([
            ['review' => $one->id(), 'level' => 'h2'],
            ['review' => $two->id(), 'level' => 'h2'],
        ]);

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<script type="application/ld\+json">(.*?)</script>~s', $html);
        preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
        $schema = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        $this->assertSame('BlogPosting', $schema['@type']);
        $this->assertSame('Six shows, one test.', $schema['description']);
        $this->assertSame('ItemList', $schema['mainEntity']['@type']);
        $this->assertSame(2, $schema['mainEntity']['numberOfItems']);

        $items = $schema['mainEntity']['itemListElement'];

        $this->assertSame([1, 2], array_column($items, 'position'), 'List items lost their document order.');
        $this->assertSame($one->absoluteUrl(), $items[0]['url']);
        $this->assertSame($two->absoluteUrl(), $items[1]['url']);
    }

    /**
     * A pin is a citation inside a sentence ("last year I saw The Stakeout"), not a list
     * item. Counting them would pad the list with shows the post is not recommending, and a
     * list that disagrees with the visible page is worse than no list at all.
     */
    public function test_inline_pins_do_not_become_list_items(): void
    {
        $this->cleanup();

        $post = EntryFacade::make()
            ->collection('posts')
            ->slug(self::SLUG)
            ->date('2026-07-30')
            ->published(true)
            ->data([
                'title' => 'Pins are not list items',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Last year I saw '],
                        ['type' => 'btsPin', 'attrs' => [
                            'id' => 'notalistitem',
                            'values' => ['type' => 'review_ref', 'review' => $this->review('110% Wizard', '2026')->id()],
                        ]],
                        ['type' => 'text', 'text' => '.'],
                    ],
                ]],
            ]);

        $post->save();

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertStringContainsString('BlogPosting', $html, 'Every post should still get a BlogPosting.');
        $this->assertStringNotContainsString('ItemList', $html);
    }

    /** Tagging a post is the whole job of getting it onto the Fringe hub. */
    public function test_a_post_tagged_fringe_is_listed_on_the_fringe_hub(): void
    {
        $post = $this->makePost([['review' => $this->review('Field Zoology 301', '2026')->id(), 'level' => 'h2']]);
        $post->set('topics', ['fringe'])->save();

        $this->get('/fringe')->assertOk()->assertSee($post->url(), false);

        $post->set('topics', [])->save();

        $this->get('/fringe')->assertOk()->assertDontSee($post->url(), false);
    }
}
