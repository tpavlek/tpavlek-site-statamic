<?php

namespace Tests\Feature;

use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * The OpenGraph card page at /og-card, which `php artisan og:card` screenshots.
 *
 * The command itself isn't covered — it shells out to Chrome, and what it does beyond that
 * is a downsample and a file write. Everything that decides what the card *says* lives in
 * the controller, and that is what this pins.
 */
class OgCardTest extends TestCase
{
    private const SLUG = 'og-card-test-post';

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

    private function makePost(array $data = [])
    {
        $this->cleanup();

        $post = EntryFacade::make()
            ->collection('posts')
            ->slug(self::SLUG)
            ->date('2026-07-30')
            ->published(true)
            ->data(array_merge([
                'title' => 'A plain title',
                'og_title' => 'A sharper headline',
                'og_description' => 'The one-line pitch.',
                'topics' => ['fringe'],
                'content' => [
                    ['type' => 'set', 'attrs' => ['id' => 'img1', 'values' => [
                        'type' => 'image',
                        'images' => ['six-shows-to-watch-at-fringe-2026/edmontask-feed.png'],
                    ]]],
                    ['type' => 'set', 'attrs' => ['id' => 'img2', 'values' => [
                        'type' => 'image',
                        'images' => ['six-shows-to-watch-at-fringe-2026/2026-110-wizard-feed.png'],
                    ]]],
                ],
            ], $data));

        $post->save();

        return $post;
    }

    public function test_the_card_is_never_indexable(): void
    {
        $this->get('/og-card?headline=Anything')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('noindex, nofollow', false);
    }

    /** Ad-hoc mode: everything comes from the query string. */
    public function test_explicit_copy_renders(): void
    {
        $response = $this->get('/og-card?'.http_build_query([
            'headline' => 'Your review deserves better than a screenshot.',
            'eyebrow' => "Troy's Fringe Reviews",
            'subhead' => 'Paste the link.',
            'footnote' => 'troypavlek.ca',
        ]));

        $response->assertOk();
        $response->assertSee('Your review deserves better than a screenshot.', false);
        $response->assertSee("Troy&#039;s Fringe Reviews", false);
        $response->assertSee('Paste the link.', false);
    }

    /**
     * An entry already carries copy written for sharing, and the card reusing it is what
     * stops the image and the share preview text saying different things.
     */
    public function test_an_entry_fills_the_card_from_its_own_sharing_copy(): void
    {
        $post = $this->makePost();

        $response = $this->get('/og-card?entry='.$post->slug());

        $response->assertOk();
        $response->assertSee('A sharper headline', false);
        $response->assertSee('The one-line pitch.', false);
        $response->assertDontSee('A plain title', false);
        // Tagged fringe, so it wears the Fringe masthead rather than the bare domain.
        $response->assertSee("Troy&#039;s Fringe Reviews", false);
    }

    public function test_an_entry_without_sharing_copy_falls_back_to_its_title(): void
    {
        $post = $this->makePost(['og_title' => null, 'og_description' => null, 'topics' => []]);

        $this->get('/og-card?entry='.$post->slug())
            ->assertOk()
            ->assertSee('A plain title', false)
            ->assertSee('troypavlek.ca', false);
    }

    /** The post's own images are the artwork, in the order they appear in it. */
    public function test_entry_images_become_the_artwork(): void
    {
        $post = $this->makePost();

        $html = $this->get('/og-card?entry='.$post->slug())->assertOk()->getContent();

        preg_match_all('~<img src="([^"]+)"~', $html, $m);

        $this->assertSame([
            '/assets/six-shows-to-watch-at-fringe-2026/edmontask-feed.png',
            '/assets/six-shows-to-watch-at-fringe-2026/2026-110-wizard-feed.png',
        ], $m[1]);
    }

    /**
     * A bare path is an asset path, matching how an entry stores one; a leading slash means
     * site root. These two disagreeing is what put a broken image on the first card built
     * with explicit artwork.
     */
    public function test_image_paths_resolve_the_same_way_entry_images_do(): void
    {
        $html = $this->get('/og-card?'.http_build_query([
            'headline' => 'Art',
            'images' => 'fringe/og/2026-field-zoology-301.png,/assets/og-image.png',
        ]))->assertOk()->getContent();

        preg_match_all('~<img src="([^"]+)"~', $html, $m);

        $this->assertSame([
            '/assets/fringe/og/2026-field-zoology-301.png',
            '/assets/og-image.png',
        ], $m[1]);
    }

    /**
     * The card is rasterised from the site itself, so a remote image is a cross-origin fetch
     * that may not have resolved when Chrome takes the shot. Better no artwork than a card
     * that renders correctly four times out of five.
     */
    public function test_remote_images_are_refused(): void
    {
        $html = $this->get('/og-card?'.http_build_query([
            'headline' => 'Art',
            'images' => 'https://example.com/evil.png,//example.com/also-evil.png',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('example.com', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /** Three is what the fan is laid out for; a fourth would sit under the others. */
    public function test_no_more_than_three_images_are_drawn(): void
    {
        $html = $this->get('/og-card?'.http_build_query([
            'headline' => 'Art',
            'images' => 'a.png,b.png,c.png,d.png',
        ]))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '<img'));
    }

    /**
     * The square card is the same page at Instagram's size, not a second implementation —
     * a separate template is how the two would end up disagreeing.
     */
    public function test_the_square_format_renders_at_instagram_size(): void
    {
        $html = $this->get('/og-card?format=square&headline=Six+shows')->assertOk()->getContent();

        $this->assertStringContainsString('width: 1080px; height: 1080px', $html);
        $this->assertStringContainsString('<body class="square">', $html);
    }

    public function test_the_default_format_is_the_link_preview(): void
    {
        foreach (['', '&format=og', '&format=nonsense'] as $suffix) {
            $html = $this->get('/og-card?headline=Six+shows'.$suffix)->assertOk()->getContent();

            $this->assertStringContainsString('width: 1200px; height: 630px', $html);
            $this->assertStringContainsString('<body class="og">', $html);
        }
    }

    /** The square card stacks, so the headline gets the full width and can run larger. */
    public function test_the_square_headline_runs_larger_than_the_link_preview(): void
    {
        $headline = urlencode('6 shows to watch at the 2026 Edmonton Fringe Festival');

        preg_match('~font-size: (\d+)px;\s*\n\s*text-wrap~', $this->get('/og-card?headline='.$headline)->getContent(), $og);
        preg_match('~font-size: (\d+)px;\s*\n\s*text-wrap~', $this->get('/og-card?format=square&headline='.$headline)->getContent(), $square);

        $this->assertGreaterThan((int) $og[1], (int) $square[1]);
    }

    /**
     * A card is a link, so it should say what following it gets you — and say it honestly.
     * A round-up promises a list; anything else promises only the post.
     */
    public function test_a_round_up_promises_the_list_and_anything_else_promises_the_post(): void
    {
        $listPost = $this->makePost(['content' => [
            ['type' => 'set', 'attrs' => ['id' => 'show1', 'values' => [
                'type' => 'show',
                'review' => EntryFacade::query()->where('collection', 'fringe_reviews')->get()->first()->id(),
            ]]],
        ]]);

        $this->get('/og-card?entry='.$listPost->slug())
            ->assertOk()
            ->assertSee('Read the full list', false);

        $prosePost = $this->makePost(['content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Just words.']]],
        ]]);

        $this->get('/og-card?entry='.$prosePost->slug())
            ->assertOk()
            ->assertSee('Read the post', false)
            ->assertDontSee('Read the full list', false);
    }

    public function test_the_call_to_action_can_be_overridden_and_switched_off(): void
    {
        $post = $this->makePost();

        $this->get('/og-card?cta=Book+tickets&entry='.$post->slug())
            ->assertOk()
            ->assertSee('Book tickets', false)
            ->assertDontSee('Read the post', false);

        $this->get('/og-card?cta=&entry='.$post->slug())
            ->assertOk()
            ->assertDontSee('class="cta"', false);
    }

    /** A portrait joins the fan as a fourth card, on top, rather than displacing artwork. */
    public function test_a_portrait_is_added_to_the_artwork(): void
    {
        $html = $this->get('/og-card?'.http_build_query([
            'headline' => 'Art',
            'images' => 'a.png,b.png,c.png',
            'portrait' => 'fringe/fringe-with-atlas-2026.jpg',
        ]))->assertOk()->getContent();

        preg_match_all('~<img[^>]*src="([^"]+)"~', $html, $m);

        $this->assertSame([
            '/assets/a.png',
            '/assets/b.png',
            '/assets/c.png',
            '/assets/fringe/fringe-with-atlas-2026.jpg',
        ], $m[1]);

        // Last in the DOM, so it stacks on top of the fan rather than behind it.
        $this->assertStringContainsString('<img class="portrait"', $html);
    }

    /**
     * The fourth card runs off the right edge rather than crowding the headline, and the
     * headline keeps its size either way.
     *
     * It has to be margin-*right*. The copy beside the fan is `flex: 1 1 auto`, so a negative
     * left margin is handed straight back to the copy and the fan doesn't move at all — which
     * is exactly the bug this replaced, and it was invisible because the card still rendered.
     */
    public function test_a_fourth_card_overhangs_the_edge_instead_of_crowding_the_headline(): void
    {
        $three = $this->get('/og-card?headline=Art&images=a.png,b.png,c.png')->getContent();
        $four = $this->get('/og-card?headline=Art&images=a.png,b.png,c.png&portrait=p.jpg')->getContent();

        $geometry = function (string $html): array {
            preg_match('~\.art \{.*?width: (\d+)px;\s*\n\s*margin-right: -(\d+)px~s', $html, $m);

            return ['width' => (int) $m[1], 'overhang' => (int) $m[2]];
        };

        $a = $geometry($three);
        $b = $geometry($four);

        $this->assertSame(0, $a['overhang'], 'Three cards fit the column they were given.');
        $this->assertGreaterThan($a['width'], $b['width'], 'A fourth card widens the fan.');

        // All of the growth goes off the right edge, so the fan's left edge doesn't move and
        // the gap after the headline is the same as it was with three.
        $this->assertSame($b['width'] - $a['width'], $b['overhang']);

        preg_match('~font-size: (\d+)px;\s*\n\s*text-wrap~', $three, $sa);
        preg_match('~font-size: (\d+)px;\s*\n\s*text-wrap~', $four, $sb);
        $this->assertSame($sa[1], $sb[1], 'The headline should not shrink to make room.');
    }

    /** A candid snapshot's subject is rarely dead centre, so the crop has to be steerable. */
    public function test_the_portrait_crop_can_be_aimed(): void
    {
        $base = ['headline' => 'Art', 'portrait' => 'a.jpg'];

        $this->get('/og-card?'.http_build_query($base))
            ->assertOk()
            ->assertSee('object-position: center', false);

        $this->get('/og-card?'.http_build_query($base + ['portrait_focus' => 'left']))
            ->assertOk()
            ->assertSee('object-position: left', false);
    }

    /** The focus lands in a stylesheet, so it can only ever be a position. */
    public function test_a_bogus_portrait_focus_falls_back_to_centre(): void
    {
        $this->get('/og-card?'.http_build_query([
            'headline' => 'Art',
            'portrait' => 'a.jpg',
            'portrait_focus' => 'left; } body { display: none } .x {',
        ]))->assertOk()->assertSee('object-position: center', false);
    }

    /** A remote portrait is refused for the same reason a remote image is. */
    public function test_a_remote_portrait_is_refused(): void
    {
        $html = $this->get('/og-card?'.http_build_query([
            'headline' => 'Art',
            'portrait' => 'https://example.com/evil.png',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('example.com', $html);
        $this->assertStringNotContainsString('class="portrait"', $html);
    }

    /** A long headline has to step down or it overflows the column beside the artwork. */
    public function test_the_headline_shrinks_as_it_lengthens(): void
    {
        $short = $this->get('/og-card?headline='.urlencode('Six shows'))->getContent();
        $long = $this->get('/og-card?headline='.urlencode(str_repeat('long headline ', 8)))->getContent();

        preg_match('~font-size: (\d+)px;\s*\n\s*text-wrap~', $short, $s);
        preg_match('~font-size: (\d+)px;\s*\n\s*text-wrap~', $long, $l);

        $this->assertGreaterThan((int) $l[1], (int) $s[1]);
    }
}
