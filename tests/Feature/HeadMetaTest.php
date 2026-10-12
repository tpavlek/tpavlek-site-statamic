<?php

namespace Tests\Feature;

use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * The bits of layout.antlers.html that a link-preview checker looks at, and that nothing
 * else would ever catch: they render, they just render wrong, and the page looks fine.
 */
class HeadMetaTest extends TestCase
{
    /**
     * Antlers keeps the newlines and indentation of a multi-line {{ if }}, and they land
     * inside the element. The title read as 97 characters when the text was 53 — long enough
     * for a checker to call it truncated in search results, and 44 characters of whitespace
     * on every page of the site.
     */
    public function test_the_title_has_no_whitespace_around_it(): void
    {
        // Both branches: a page with its own og_title, and one falling back to the site name.
        foreach (['/fringe/reviews', '/videos'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match('~<title>(.*?)</title>~s', $html, $m);

            $this->assertNotEmpty($m[1] ?? '', "No title on {$url}.");
            $this->assertSame(trim($m[1]), $m[1], "The title on {$url} is padded with whitespace.");
        }
    }

    /** Discord and Slack print this above the title; without it a shared link is anonymous. */
    public function test_every_page_names_the_site(): void
    {
        foreach (['/', '/fringe', '/fringe/reviews', '/videos'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<meta property="og:site_name" content="Troy Pavlek">', false);
        }
    }

    /**
     * Antlers doesn't escape, so a title opening with a quote closed every content="" it
     * landed in, and the share preview read as empty. Uses its own throwaway entry.
     */
    public function test_quotes_in_a_title_and_description_stay_inside_the_attribute(): void
    {
        $slug = 'head-meta-quote-test';
        $entry = EntryFacade::make()
            ->collection('videos')
            ->slug($slug)
            ->published(true)
            ->data([
                'title' => '"Quoted" title',
                'publish_date' => '2099-01-01 00:00',
                'content' => '"Quoted" description.',
            ]);
        $entry->save();

        try {
            $html = $this->get($entry->url())->assertOk()->getContent();

            $this->assertStringContainsString('<meta property="og:title" content="&quot;Quoted&quot; title">', $html);
            $this->assertStringContainsString('<meta name="description" content="&quot;Quoted&quot; description.">', $html);
        } finally {
            $entry->delete();
        }
    }
}
