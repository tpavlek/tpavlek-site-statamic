<?php

namespace App\Feed;

use App\Fringe\FestivalUrls;
use Carbon\Carbon;

/**
 * RSS 2.0 documents, built as a string rather than through a template.
 *
 * A feed is markup with exactly one consumer type and no design, so it has no business in a
 * view; building it here means the escaping is done in one place and can't be undone by an
 * editor tidying up whitespace in a template.
 *
 * RSS 2.0 rather than Atom or JSON Feed: it's the format every reader, aggregator and
 * crawler handles without qualification, and this is a feed whose whole value is being read
 * by things that were written years ago and things that were written last week.
 */
class Feed
{
    /**
     * @param  array<int, array{title: string, url: string, date: ?Carbon, description: string, content: ?string}>  $items
     */
    public static function rss(string $title, string $description, string $selfPath, string $sitePath, array $items): string
    {
        $self = FestivalUrls::absolute($selfPath);
        $site = FestivalUrls::absolute($sitePath);

        // The newest item's date, not the moment of the request: a lastBuildDate that moves
        // on every fetch tells a reader the feed changed when it didn't, which is how a feed
        // trains its subscribers to ignore it.
        $lastBuild = collect($items)->pluck('date')->filter()->max();

        $body = implode('', array_map(self::item(...), $items));

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">'."\n"
            .'<channel>'."\n"
            .self::tag('title', $title)
            .self::tag('link', $site)
            .self::tag('description', $description)
            .self::tag('language', 'en-ca')
            .($lastBuild ? self::tag('lastBuildDate', $lastBuild->toRfc2822String()) : '')
            // Where this feed lives. Without it a reader that acquired the feed by any route
            // other than its own URL has no way to refresh it.
            .'<atom:link href="'.htmlspecialchars($self, ENT_XML1).'" rel="self" type="application/rss+xml" />'."\n"
            .$body
            .'</channel>'."\n"
            .'</rss>'."\n";
    }

    private static function item(array $item): string
    {
        $url = FestivalUrls::absolute($item['url']);

        return '<item>'."\n"
            .self::tag('title', $item['title'])
            .self::tag('link', $url)
            // The URL as the id, and isPermaLink saying so. The default is that a guid *is*
            // a permalink, and a reader that follows a guid which isn't one gets a 404.
            .'<guid isPermaLink="true">'.htmlspecialchars($url, ENT_XML1).'</guid>'."\n"
            .($item['date'] ? self::tag('pubDate', $item['date']->toRfc2822String()) : '')
            .self::tag('description', $item['description'])
            // The full text, where there is one, in the element readers actually look in for
            // it. description stays a plain-text summary so a reader showing only one of the
            // two still shows something sensible.
            .(($item['content'] ?? null) ? '<content:encoded><![CDATA['.self::cdataSafe($item['content']).']]></content:encoded>'."\n" : '')
            .'</item>'."\n";
    }

    private static function tag(string $name, string $value): string
    {
        return "<{$name}>".htmlspecialchars($value, ENT_XML1, 'UTF-8')."</{$name}>\n";
    }

    /**
     * The one sequence that can end a CDATA section early. Splitting it across two sections
     * is the standard escape — there is no character escape available inside CDATA.
     */
    private static function cdataSafe(string $html): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $html);
    }

    /**
     * Feed content is read somewhere else entirely, so a root-relative link in it resolves
     * against the reader's own host and 404s. Only src/href starting with a single slash are
     * touched; "//cdn" is protocol-relative and already absolute.
     */
    public static function absolutise(string $html): string
    {
        return preg_replace_callback(
            '~\b(href|src)="(/(?!/)[^"]*)"~i',
            fn (array $m) => $m[1].'="'.FestivalUrls::absolute($m[2]).'"',
            $html,
        );
    }
}
