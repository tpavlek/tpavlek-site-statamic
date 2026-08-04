<?php

namespace Tests\Feature;

use App\Fringe\PostFestivals;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * Where a Fringe post shows up: every one of them on the /fringe hub, and the ones about a
 * given festival on that year's reviews index.
 *
 * The two are tagged differently on purpose. `topics: fringe` is the subject and is what the
 * hub lists on; the year is derived from the shows a post headlines, so a round-up cannot
 * disagree with its own contents and needs no second tag. `fringe_festival` on the post is
 * the override for a Fringe post that names no shows.
 */
class FringePostListingTest extends TestCase
{
    private const SLUG = 'fringe-listing-test-post';

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

    private function review(string $title, string $festival)
    {
        $review = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->value('title') === $title && $e->value('festival') === $festival);

        $this->assertNotNull($review, "No fringe_reviews entry titled {$title} at Fringe {$festival}.");

        return $review;
    }

    private function makePost(array $data = [], array $showFestivals = [])
    {
        $this->cleanup();

        $content = collect($showFestivals)->map(fn ($festival, $i) => [
            'type' => 'set',
            'attrs' => ['id' => 'show'.$i, 'values' => [
                'type' => 'show',
                'review' => $this->review(...$festival)->id(),
                'level' => 'h2',
            ]],
        ])->all();

        $post = EntryFacade::make()
            ->collection('posts')
            ->slug(self::SLUG)
            ->date('2026-07-30')
            ->published(true)
            ->data(array_merge([
                'title' => 'Fringe listing test',
                'og_description' => 'A test post about the Fringe.',
                'topics' => ['fringe'],
                'content' => $content,
            ], $data));

        $post->save();

        return $post;
    }

    /** Every Fringe post, whatever year it is about. */
    public function test_the_hub_lists_a_post_tagged_fringe(): void
    {
        $post = $this->makePost();

        $this->get('/fringe')->assertOk()->assertSee($post->url(), false);
    }

    public function test_the_hub_ignores_a_post_not_tagged_fringe(): void
    {
        $post = $this->makePost(['topics' => []]);

        $this->get('/fringe')->assertOk()->assertDontSee($post->url(), false);
    }

    /**
     * The point of the whole arrangement: readers land on the reviews page from search and
     * never see the hub, so the writing has to be reachable from there too.
     */
    public function test_the_year_index_lists_a_post_about_that_festival(): void
    {
        $post = $this->makePost([], [['Field Zoology 301', '2026']]);

        $this->get('/fringe/reviews')->assertOk()->assertSee($post->url(), false);
        $this->get('/fringe/2025/reviews')->assertOk()->assertDontSee($post->url(), false);
        $this->get('/fringe/2024/reviews')->assertOk()->assertDontSee($post->url(), false);
    }

    /** The year comes from the shows the post headlines, with no second tag to keep in sync. */
    public function test_the_festival_is_derived_from_the_shows_the_post_headlines(): void
    {
        $post = $this->makePost([], [['Field Zoology 301', '2026']]);

        $this->assertSame(['2026'], PostFestivals::for($post));
    }

    /** A post spanning two festivals belongs to both, newest first. */
    public function test_a_post_can_cover_more_than_one_festival(): void
    {
        $post = $this->makePost([], [
            ['Field Zoology 301', '2026'],
            ['Sketchy Broads: Easy Bake Coven', '2025'],
        ]);

        $this->assertSame(['2026', '2025'], PostFestivals::for($post));

        $this->get('/fringe/reviews')->assertOk()->assertSee($post->url(), false);
        $this->get('/fringe/2025/reviews')->assertOk()->assertSee($post->url(), false);
    }

    /**
     * A pin is a citation inside a sentence. A 2026 post that mentions a 2024 show would
     * otherwise file itself under 2024 and turn up on an archive page it has nothing to do
     * with. Same rule as the ItemList in App\Schema\PostSchema.
     */
    public function test_a_show_mentioned_in_passing_does_not_file_the_post_under_its_year(): void
    {
        $post = $this->makePost(['content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Last year I saw '],
                ['type' => 'btsPin', 'attrs' => [
                    'id' => 'citation',
                    'values' => ['type' => 'review_ref', 'review' => $this->review('Sketchy Broads: Choosing the Bear', '2024')->id()],
                ]],
                ['type' => 'text', 'text' => '.'],
            ],
        ]]]);

        $this->assertSame([], PostFestivals::for($post));

        $this->get('/fringe/2024/reviews')->assertOk()->assertDontSee($post->url(), false);
    }

    /** The override, for a Fringe post that headlines no shows at all. */
    public function test_an_explicit_festival_tag_wins(): void
    {
        $post = $this->makePost(
            ['fringe_festival' => ['2025']],
            [['Field Zoology 301', '2026']]
        );

        $this->assertSame(['2025'], PostFestivals::for($post));

        $this->get('/fringe/2025/reviews')->assertOk()->assertSee($post->url(), false);
        $this->get('/fringe/reviews')->assertOk()->assertDontSee($post->url(), false);
    }

    /** Tagged for a year but not tagged fringe is not Fringe writing. */
    public function test_the_year_index_still_requires_the_fringe_topic(): void
    {
        $post = $this->makePost(['topics' => []], [['Field Zoology 301', '2026']]);

        $this->get('/fringe/reviews')->assertOk()->assertDontSee($post->url(), false);
    }
}
