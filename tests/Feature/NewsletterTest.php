<?php

namespace Tests\Feature;

use App\Actions\DraftNewsletter;
use App\Actions\PreviewNewsletter;
use App\Newsletter\Kit;
use App\Newsletter\PostEmail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * Posts and video articles as newsletter emails, and drafting them into Kit.
 *
 * Kit is faked throughout: nothing here talks to the real account. Every entry is made
 * and deleted by the test itself, never real content.
 */
class NewsletterTest extends TestCase
{
    private const POST = 'newsletter-test-post';

    private const VIDEO = 'newsletter-test-video';

    private function cleanup(): void
    {
        EntryFacade::query()->whereIn('collection', ['posts', 'videos'])->get()
            ->filter(fn ($e) => in_array($e->slug(), [self::POST, self::VIDEO]))
            ->each->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    private function paragraph(string $text, ?string $href = null): array
    {
        $node = ['type' => 'text', 'text' => $text];

        if ($href) {
            $node['marks'] = [['type' => 'link', 'attrs' => ['href' => $href]]];
        }

        return ['type' => 'paragraph', 'content' => [$node]];
    }

    private function makePost(array $extra = [])
    {
        $this->cleanup();

        $entry = EntryFacade::make()
            ->collection('posts')
            ->slug(self::POST)
            ->date('2026-07-30')
            ->published(true)
            ->data(array_merge([
                'title' => 'Newsletter test post',
                'og_description' => 'The summary line.',
                'content' => [
                    $this->paragraph('Opening paragraph.'),
                    $this->paragraph('a relative link', '/fringe/reviews'),
                    ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'images' => ['fringe/100-wizard.webp'], 'alignment' => 'left', 'caption' => 'A wizard.']]],
                    ['type' => 'set', 'attrs' => ['values' => ['type' => 'pull_quote', 'text' => 'Quote me.']]],
                    ['type' => 'set', 'attrs' => ['values' => ['type' => 'email_subscription_prompt']]],
                ],
            ], $extra));

        $entry->save();

        return $entry;
    }

    private function makeVideo()
    {
        $this->cleanup();

        $entry = EntryFacade::make()
            ->collection('videos')
            ->slug(self::VIDEO)
            ->published(true)
            ->data([
                'title' => 'Newsletter test video',
                'youtube' => 'abc123XYZ',
                'publish_date' => '2099-01-01 00:00',
                'content' => 'The YouTube description.',
                'article' => [$this->paragraph('The companion article.')],
            ]);

        $entry->save();

        return $entry;
    }

    public function test_a_post_renders_as_email_safe_html(): void
    {
        $html = (new PostEmail($this->makePost()))->html();
        $base = rtrim(config('app.url'), '/');

        $this->assertStringContainsString('Opening paragraph.', $html);
        $this->assertStringContainsString('Quote me.', $html);
        $this->assertStringContainsString('A wizard.', $html);

        // Styles are inlined and the classes they came from are gone.
        $this->assertStringContainsString('style="', $html);
        $this->assertStringNotContainsString(' class=', $html);

        // Every link and image is absolute; an email has no site to be relative to.
        $this->assertStringContainsString('href="'.$base.'/fringe/reviews"', $html);
        $this->assertStringContainsString('src="'.$base.'/assets/fringe/100-wizard.webp"', $html);
        $this->assertDoesNotMatchRegularExpression('~(href|src)="/(?!/)~', $html);

        // The subscribe prompt is pointless in an email to subscribers.
        $this->assertStringNotContainsString('Get updates straight to your inbox', $html);

        // Just the body: Kit's template supplies the document around it.
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /** Galleries are table rows: one row for `row`, two images per row for `grid`. */
    public function test_galleries_render_their_images_in_table_rows(): void
    {
        // Three different assets: an assets field collapses repeats of the same one.
        $images = [
            'who-cares-about-an-election/alberta-2023-dot-map.png',
            'who-cares-about-an-election/alberta-2023-filled-map.png',
            'who-cares-about-an-election/edmonton-infill-dot-map.png',
        ];

        foreach (['row' => 1, 'grid' => 2] as $layout => $rows) {
            $post = $this->makePost(['content' => [
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'gallery', 'media' => $images, 'layout' => $layout]]],
            ]]);

            $html = (new PostEmail($post))->html();

            $this->assertSame(3, substr_count($html, '<img'), "Every image in a {$layout} gallery renders.");
            $this->assertSame($rows, substr_count($html, '<tr'), "A {$layout} gallery of three makes {$rows} row(s).");
        }
    }

    /**
     * Images get a pixel width from their own proportions, not width: 100%. Gmail gives the
     * email a far wider column than it was designed for, and a portrait image at full width
     * was several screens tall.
     */
    public function test_images_are_sized_so_portraits_stay_short(): void
    {
        // A portrait map: 700 x 1180.
        $post = $this->makePost(['content' => [
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'image', 'images' => ['who-cares-about-an-election/alberta-2023-filled-map.png']]]],
        ]]);

        $html = (new PostEmail($post))->html();

        preg_match('~<img[^>]*alberta-2023-filled-map\.png[^>]*>~', $html, $img);
        preg_match('~width="(\d+)"~', $img[0], $width);

        // 520px tall at most, so about 308 wide, and still able to shrink on a phone.
        $this->assertLessThanOrEqual(310, (int) $width[1]);
        $this->assertStringContainsString('max-width: 100%', $img[0]);
    }

    public function test_a_video_article_leads_with_a_linked_thumbnail_instead_of_an_embed(): void
    {
        $video = $this->makeVideo();
        $email = new PostEmail($video);
        $html = $email->html();

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('i.ytimg.com/vi/abc123XYZ', $html);
        $this->assertStringContainsString('Watch the video', $html);
        $this->assertStringContainsString($video->absoluteUrl(), $html);
        $this->assertStringContainsString('The companion article.', $html);
        $this->assertSame('The YouTube description.', $email->previewText());
    }

    public function test_only_posts_and_videos_with_an_article_can_be_newsletters(): void
    {
        $this->assertTrue(PostEmail::supports($this->makePost()));
        $this->assertTrue(PostEmail::supports($this->makeVideo()));

        $video = $this->makeVideo();
        $video->set('article', null)->save();

        $this->assertFalse(PostEmail::supports($video));
    }

    public function test_drafting_saves_a_kit_draft_and_records_it_once(): void
    {
        Http::fake(['api.kit.com/*' => Http::response(['broadcast' => ['id' => 4242, 'status' => 'draft']], 201)]);
        $this->app->instance(Kit::class, new Kit('kit_test_key'));

        $post = $this->makePost();
        $message = (new DraftNewsletter)->run(collect([$post]), []);

        $this->assertStringContainsString('Draft created', $message);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.kit.com/v4/broadcasts'
                && $request->header('X-Kit-Api-Key')[0] === 'kit_test_key'
                && $request['subject'] === 'Newsletter test post'
                && $request['preview_text'] === 'The summary line.'
                && $request['send_at'] === null
                && $request['public'] === false
                && str_contains($request['content'], 'Opening paragraph.');
        });

        $saved = EntryFacade::find($post->id());
        $this->assertSame(4242, $saved->value('newsletter_broadcast_id'));
        $this->assertNotEmpty($saved->value('newsletter_drafted_at'));

        // A second click doesn't make a second draft unless asked to.
        $again = (new DraftNewsletter)->run(collect([$saved]), []);
        $this->assertStringContainsString('Already drafted', $again);
        Http::assertSentCount(1);

        (new DraftNewsletter)->run(collect([$saved]), ['again' => true]);
        Http::assertSentCount(2);
    }

    public function test_a_kit_error_is_reported_rather_than_recorded_as_a_draft(): void
    {
        Http::fake(['api.kit.com/*' => Http::response(['errors' => ['Subject is too long']], 422)]);
        $this->app->instance(Kit::class, new Kit('kit_test_key'));

        $post = $this->makePost();

        try {
            (new DraftNewsletter)->run(collect([$post]), []);
            $this->fail('Expected Kit\'s refusal to surface.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Subject is too long', $e->getMessage());
        }

        $this->assertNull(EntryFacade::find($post->id())->value('newsletter_broadcast_id'));
    }

    public function test_the_preview_is_behind_the_cp_login(): void
    {
        $post = $this->makePost();
        $url = (new PreviewNewsletter)->redirect(collect([$post]), []);

        $this->assertStringContainsString('/newsletter/'.$post->id().'/preview', $url);
        $this->get($url)->assertRedirect();
    }
}
