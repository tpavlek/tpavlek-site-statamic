<?php

namespace Tests\Feature;

use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * A video with a companion article reads as a post: article layout, BlogPosting markup, and
 * a place on /posts and in /feed.xml. A video without one keeps the plain video page and
 * stays out of both.
 *
 * Everything here creates and deletes its own entries rather than editing real content.
 */
class VideoArticleTest extends TestCase
{
    private const WITH = 'video-article-test-with';

    private const WITHOUT = 'video-article-test-without';

    private function cleanup(): void
    {
        EntryFacade::query()->where('collection', 'videos')->get()
            ->filter(fn ($e) => in_array($e->slug(), [self::WITH, self::WITHOUT]))
            ->each->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    private function makeVideo(string $slug, array $extra = [])
    {
        $entry = EntryFacade::make()
            ->collection('videos')
            ->slug($slug)
            ->published(true)
            ->data(array_merge([
                'title' => "Video test {$slug}",
                'youtube' => 'abc123XYZ',
                // Far future, so these sort first in /posts and inside the feed's 30-item window.
                'publish_date' => '2099-01-01 00:00',
                'content' => 'The YouTube description, which is also the summary.',
            ], $extra));

        $entry->save();

        return $entry;
    }

    private function article(string $text): array
    {
        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]];
    }

    public function test_a_video_with_an_article_renders_the_article_and_a_blog_posting(): void
    {
        $this->cleanup();
        $video = $this->makeVideo(self::WITH, ['article' => $this->article('The companion article body.')]);

        $html = $this->get($video->url())->assertOk()->getContent();

        $this->assertStringContainsString('The companion article body.', $html);
        $this->assertStringContainsString('"@type": "BlogPosting"', $html);
        $this->assertStringContainsString('"@type": "VideoObject"', $html);
        $this->assertStringContainsString('og:type" content="article"', $html);
        // The description is the summary here, not body copy.
        $this->assertStringNotContainsString('<p>The YouTube description', $html);
    }

    public function test_a_video_without_an_article_keeps_the_plain_page(): void
    {
        $this->cleanup();
        $video = $this->makeVideo(self::WITHOUT);

        $html = $this->get($video->url())->assertOk()->getContent();

        $this->assertStringContainsString('<p>The YouTube description', $html);
        $this->assertStringContainsString('"@type": "VideoObject"', $html);
        $this->assertStringNotContainsString('BlogPosting', $html);
        // The summary comes from the description, not the layout's Fringe boilerplate.
        $this->assertStringContainsString('name="description" content="The YouTube description', $html);
    }

    public function test_only_videos_with_an_article_are_listed_as_writing(): void
    {
        $this->cleanup();
        $this->makeVideo(self::WITH, ['article' => $this->article('Body.')]);
        $this->makeVideo(self::WITHOUT);

        $posts = $this->get('/posts')->assertOk()->getContent();
        $this->assertStringContainsString('/video/'.self::WITH, $posts);
        $this->assertStringNotContainsString('/video/'.self::WITHOUT, $posts);

        $feed = $this->get('/feed.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/video/'.self::WITH, $feed);
        $this->assertStringNotContainsString('/video/'.self::WITHOUT, $feed);
    }
}
