<?php

namespace Tests\Feature;

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
}
