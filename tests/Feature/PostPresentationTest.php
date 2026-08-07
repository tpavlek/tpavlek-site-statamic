<?php

namespace Tests\Feature;

use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\User as UserFacade;
use Tests\TestCase;

/**
 * Small presentation guarantees on posts and the share card that are easy to break and give
 * no error when they do.
 *
 * Everything here creates and deletes its own entry rather than editing real content.
 */
class PostPresentationTest extends TestCase
{
    private const SLUG = 'presentation-test-post';

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

    private function makePost(array $content, array $extra = [])
    {
        $this->cleanup();

        $entry = EntryFacade::make()
            ->collection('posts')
            ->slug(self::SLUG)
            ->date('2026-07-30')
            ->published(true)
            ->data(array_merge(['title' => 'Presentation test', 'content' => $content], $extra));

        $entry->save();

        return $entry;
    }

    public function test_a_post_with_no_related_videos_hides_the_heading(): void
    {
        $post = $this->makePost([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Some words.']]]]);

        $this->get($post->url())->assertDontSee('Related videos', false);
    }

    public function test_an_image_floats_when_told_to_and_is_full_width_by_default(): void
    {
        $asset = 'fringe/100-wizard.webp';

        $floated = $this->makePost([[
            'type' => 'set',
            'attrs' => ['values' => ['type' => 'image', 'images' => [$asset], 'alignment' => 'left', 'caption' => 'A wizard.']],
        ]]);

        $html = $this->get($floated->url())->getContent();
        $this->assertStringContainsString('post-image--left', $html);
        $this->assertStringContainsString('A wizard.', $html);

        // An image set saved before the alignment field existed has no value for it, and
        // must keep behaving the way it always did.
        $legacy = $this->makePost([[
            'type' => 'set',
            'attrs' => ['values' => ['type' => 'image', 'images' => [$asset]]],
        ]]);

        $html = $this->get($legacy->url())->getContent();
        $this->assertStringContainsString('post-image--full', $html);
        $this->assertStringNotContainsString('post-image--left', $html);
    }

    /**
     * A rule is the thing that lets two images face each other: float one left, drop a rule,
     * float the next right. Without `clear: both` the rule sits in the gutter beside the
     * first float and the following text never moves down.
     */
    public function test_a_horizontal_rule_is_available_and_renders(): void
    {
        $field = \Statamic\Facades\Collection::findByHandle('posts')->entryBlueprint()->field('content');

        $this->assertContains('horizontalrule', $field->get('buttons'), 'The rule button is missing from the posts editor.');

        $post = $this->makePost([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before.']]],
            ['type' => 'horizontalRule'],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After.']]],
        ]);

        $this->get($post->url())->assertSee('<hr>', false);
    }

    /** The same clearing as a rule, drawing nothing. */
    public function test_an_invisible_break_clears_without_drawing_anything(): void
    {
        $post = $this->makePost([
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'images' => ['fringe/100-wizard.webp'], 'alignment' => 'left']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'break', 'space' => 'large']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After the break.']]],
        ]);

        $html = $this->get($post->url())->getContent();

        $this->assertStringContainsString('post-break post-break--large', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        // Invisible means invisible: no rule drawn alongside it.
        $this->assertStringNotContainsString('<hr>', $html);
    }

    public function test_a_break_defaults_to_no_extra_space(): void
    {
        $post = $this->makePost([
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'break']]],
        ]);

        $this->get($post->url())->assertSee('post-break post-break--none', false);
    }

    public function test_the_share_card_says_whether_a_show_already_has_an_og_image(): void
    {
        $withOg = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->value('og_image'));

        $admin = $this->actingAs(UserFacade::all()->first())
            ->get(cp_route('fringe-social-card.show', $withOg->id()));

        $admin->assertOk();
        $admin->assertSee('already has an OpenGraph image', false);
        $admin->assertSee('View it in a new tab', false);
        $admin->assertSee('target="_blank"', false);
    }

    public function test_the_share_card_says_when_a_show_has_no_og_image_yet(): void
    {
        $withoutOg = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => ! $e->value('og_image'));

        $admin = $this->actingAs(UserFacade::all()->first())
            ->get(cp_route('fringe-social-card.show', $withoutOg->id()));

        $admin->assertOk();
        $admin->assertSee('no OpenGraph image yet', false);
    }

    public function test_the_card_offers_star_colours_and_defaults_to_white(): void
    {
        $review = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => ! ($e->value('share_card_options')['stars_colour'] ?? null));

        $response = $this->get($review->url().'/share-card');

        $response->assertOk();
        $response->assertSee('"starsColour":"white"', false);
        foreach (['swatch--white', 'swatch--black', 'swatch--gold', 'swatch--teal'] as $swatch) {
            $response->assertSee($swatch, false);
        }
    }

    /**
     * Both are client-side behaviours, so what's asserted here is the wiring: that the card
     * binds its quotation marks to hasQuote, and that the scrim is derived from the measured
     * panel rather than the old fixed slice of the card.
     */
    public function test_the_card_is_wired_for_empty_quotes_and_a_measured_scrim(): void
    {
        $review = EntryFacade::query()->where('collection', 'fringe_reviews')->get()->first();

        $html = $this->get($review->url().'/share-card')->getContent();

        $this->assertStringContainsString('x-show="hasQuote"', $html);
        $this->assertStringContainsString('x-ref="panel"', $html);
        $this->assertStringContainsString('panelHeight', $html);
        $this->assertStringContainsString('ResizeObserver', $html);
        // The old scrim was a fixed plateau at position ±12% fading to ±38%.
        $this->assertStringNotContainsString('${p - 38}%', $html);
        $this->assertStringNotContainsString('${p + 12}%', $html);
    }

    public function test_star_colour_and_an_empty_quote_survive_a_save(): void
    {
        $review = EntryFacade::make()
            ->collection('fringe_reviews')
            ->slug('share-card-options-test')
            ->date('2026-08-15')
            ->data(['title' => 'Share card options test', 'festival' => '2026', 'recommendation' => 'watchlist', 'stars' => 4]);
        $review->save();

        try {
            $this->actingAs(UserFacade::all()->first())
                ->post(cp_route('fringe-social-card.save', $review->id()), [
                    'quote' => '',
                    'position' => 100,
                    'focal_x' => 50,
                    'focal_y' => 50,
                    'zoom' => 100,
                    'text_size' => 42,
                    'stars_enabled' => 1,
                    'stars_colour' => 'gold',
                    'title_enabled' => 1,
                    'title_text' => 'Share card options test',
                    'title_size' => 44,
                    'attribution_enabled' => 1,
                    'attribution_text' => '— Someone',
                    'attribution_size' => 34,
                ])
                ->assertSessionHasNoErrors();

            $options = EntryFacade::find($review->id())->value('share_card_options');

            $this->assertSame('gold', $options['stars_colour']);
            $this->assertTrue($options['stars_enabled']);
        } finally {
            EntryFacade::find($review->id())?->delete();
        }
    }

    public function test_an_invalid_star_colour_is_rejected(): void
    {
        $review = EntryFacade::make()
            ->collection('fringe_reviews')
            ->slug('share-card-options-test')
            ->date('2026-08-15')
            ->data(['title' => 'Share card options test', 'festival' => '2026', 'recommendation' => 'watchlist']);
        $review->save();

        try {
            $this->actingAs(UserFacade::all()->first())
                ->from($review->url().'/share-card')
                ->post(cp_route('fringe-social-card.save', $review->id()), [
                    'quote' => 'Fine.',
                    'position' => 100, 'focal_x' => 50, 'focal_y' => 50, 'text_size' => 42,
                    'stars_enabled' => 1, 'stars_colour' => 'magenta',
                    'attribution_enabled' => 0, 'attribution_size' => 34,
                ])
                ->assertSessionHasErrors('stars_colour');
        } finally {
            EntryFacade::find($review->id())?->delete();
        }
    }

    public function test_the_og_notice_never_reaches_anonymous_visitors(): void
    {
        $withOg = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->value('og_image'));

        $this->get($withOg->url().'/share-card')->assertDontSee('already has an OpenGraph image', false);
    }
}
