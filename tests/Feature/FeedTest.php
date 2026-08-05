<?php

namespace Tests\Feature;

use App\Fringe\FestivalUrls;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * A broken feed fails silently — readers just stop showing new items, and nobody reports it.
 * So these check the things that break quietly: XML that won't parse, relative links that
 * resolve against the reader's host, and a rating claiming a year it didn't earn.
 */
class FeedTest extends TestCase
{
    private function feed(string $url): \SimpleXMLElement
    {
        $response = $this->get($url);

        $response->assertOk();
        $this->assertStringContainsString('application/rss+xml', $response->headers->get('content-type'));

        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml, "{$url} did not parse as XML.");

        return $xml;
    }

    public function test_the_posts_feed_parses_and_has_items(): void
    {
        $xml = $this->feed('/feed.xml');

        $this->assertGreaterThan(0, count($xml->channel->item));
        $this->assertSame('https://troypavlek.ca/posts', (string) $xml->channel->link);
    }

    public function test_the_reviews_feed_covers_the_current_festival(): void
    {
        $current = FestivalUrls::currentSlug();
        $xml = $this->feed('/fringe/reviews/feed.xml');

        $this->assertGreaterThan(0, count($xml->channel->item));
        $this->assertStringContainsString($current, (string) $xml->channel->title);

        // Every item is a show from this festival, and nothing from an archive year leaked in.
        foreach ($xml->channel->item as $item) {
            $this->assertStringStartsWith(
                "https://troypavlek.ca/fringe/{$current}/reviews/",
                (string) $item->link,
            );
        }
    }

    /**
     * A guid that says isPermaLink="true" and isn't one sends every subscriber to a 404.
     */
    public function test_every_item_guid_is_the_item_url(): void
    {
        foreach (['/feed.xml', '/fringe/reviews/feed.xml'] as $url) {
            foreach ($this->feed($url)->channel->item as $item) {
                $this->assertSame((string) $item->link, (string) $item->guid, "in {$url}");
            }
        }
    }

    /**
     * Feed content is read on someone else's host, where a root-relative href resolves
     * against *their* origin and 404s.
     */
    public function test_review_content_carries_no_relative_links(): void
    {
        $xml = $this->feed('/fringe/reviews/feed.xml');

        $withContent = 0;

        foreach ($xml->channel->item as $item) {
            $content = (string) $item->children('content', true)->encoded;

            if ($content === '') {
                continue;
            }

            $withContent++;
            $this->assertDoesNotMatchRegularExpression('~\b(href|src)="/(?!/)~', $content);
        }

        $this->assertGreaterThan(0, $withContent, 'No feed item carried any content at all.');
    }

    /**
     * A returning show borrows last year's stars. Printing them against this year's title
     * without saying so claims a verdict Troy hasn't reached — the exact bug App\Fringe\
     * ReviewRating was written to prevent, so the feed must not reintroduce it.
     */
    public function test_an_inherited_rating_names_the_year_it_was_earned(): void
    {
        $current = FestivalUrls::currentSlug();

        $inherited = EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get()
            ->first(function (Entry $entry) use ($current) {
                $rating = $entry->augmentedValue('rating')->value();

                return $entry->festival?->slug() === $current && ($rating['inherited'] ?? false);
            });

        if (! $inherited) {
            $this->markTestSkipped('No returning show this festival is wearing an inherited rating.');
        }

        $xml = $this->feed('/fringe/reviews/feed.xml');
        $title = null;

        foreach ($xml->channel->item as $item) {
            if ((string) $item->link === FestivalUrls::absolute($inherited->url())) {
                $title = (string) $item->title;
            }
        }

        $this->assertNotNull($title);
        $this->assertStringContainsString(
            '(reviewed '.$inherited->augmentedValue('rating')->value()['year'].')',
            $title,
        );
    }

    /**
     * Autodiscovery is how a reader handed any page of the site finds the feed at all.
     */
    public function test_pages_advertise_their_feeds(): void
    {
        $html = $this->get('/fringe/reviews')->assertOk()->getContent();

        $this->assertStringContainsString('type="application/rss+xml"', $html);
        $this->assertStringContainsString('href="/feed.xml"', $html);
        $this->assertStringContainsString('href="/fringe/reviews/feed.xml"', $html);
    }

    /**
     * An archive year is finished, so a feed of it would never update again.
     */
    public function test_an_archive_year_advertises_no_reviews_feed(): void
    {
        $archive = collect(\Statamic\Facades\Term::query()->where('taxonomy', 'fringe_festival')->get())
            ->first(fn ($term) => ! FestivalUrls::isCurrent($term->slug()));

        if (! $archive) {
            $this->markTestSkipped('Only one festival exists.');
        }

        $html = $this->get("/fringe/{$archive->slug()}/reviews")->assertOk()->getContent();

        $this->assertStringNotContainsString('href="/fringe/reviews/feed.xml"', $html);
    }
}
