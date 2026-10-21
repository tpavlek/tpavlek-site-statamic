<?php

namespace App\Schema;

use App\Support\BardSets;
use App\Support\Writing;
use Statamic\Support\Str;

/**
 * schema.org markup for a video page.
 *
 * Registered as the `video_schema` computed field on the videos collection and emitted by
 * videos/show. A plain video page is a VideoObject. A video with a companion article is a
 * BlogPosting with the VideoObject as its `video` — the page is primarily something to read,
 * and that's the shape Google documents for an article with an embedded video.
 *
 * thumbnailUrl and uploadDate are the two fields Google requires of a VideoObject, so both
 * always have a value: the cover we uploaded, or YouTube's own thumbnail for the older
 * entries that never got one.
 */
class VideoSchema
{
    public static function build($entry): ?string
    {
        $video = self::videoObject($entry);

        if (Writing::hasArticle($entry)) {
            $data = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $entry->value('title'),
                'url' => $entry->absoluteUrl(),
                'mainEntityOfPage' => $entry->absoluteUrl(),
                'description' => self::description($entry),
                'image' => $video['thumbnailUrl'] ?? null,
                'datePublished' => Writing::date($entry)?->toIso8601String(),
                'dateModified' => self::dateModified($entry),
                'author' => PostSchema::author(),
                'video' => $video,
            ]);
        } else {
            $data = ['@context' => 'https://schema.org', ...$video];
        }

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );
    }

    /**
     * The summary for the meta description and the structured data, shared with the computed
     * og_description so they say the same thing.
     *
     * A video with an article takes it from the article's opening: that's the page's real
     * content, and the YouTube description (written to pull people off YouTube, and never
     * updated after) usually says less, and sometimes something else. Paragraphs are taken
     * until there's a sentence or two, because an article can open on a three-word hook.
     * Without an article, it's the start of the description, which is what YouTube shows
     * under the video.
     */
    public static function description($entry): ?string
    {
        if (Writing::hasArticle($entry)) {
            $text = '';

            foreach (BardSets::paragraphs($entry->value('article')) as $paragraph) {
                $text = trim("{$text} {$paragraph}");

                if (mb_strlen($text) >= 110) {
                    break;
                }
            }

            if ($text !== '') {
                return Str::safeTruncate($text, 200, '…');
            }
        }

        $html = (string) ($entry->augmentedValue('content')->value() ?? '');
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        // safeTruncate, so a summary never ends half a word in.
        return $text !== '' ? Str::safeTruncate($text, 200, '…') : null;
    }

    private static function videoObject($entry): array
    {
        $youtube = $entry->value('youtube');

        return array_filter([
            '@type' => 'VideoObject',
            'name' => $entry->value('title'),
            'description' => self::description($entry) ?? $entry->value('title'),
            'thumbnailUrl' => self::thumbnail($entry),
            'uploadDate' => Writing::date($entry)?->toIso8601String(),
            'url' => $entry->absoluteUrl(),
            'embedUrl' => $youtube ? "https://www.youtube.com/embed/{$youtube}" : null,
        ]);
    }

    private static function thumbnail($entry): ?string
    {
        $asset = $entry->augmentedValue('thumbnail')?->value();
        $asset = is_iterable($asset) ? collect($asset)->first() : $asset;

        if ($asset) {
            return $asset->absoluteUrl();
        }

        $youtube = $entry->value('youtube');

        return $youtube ? "https://i.ytimg.com/vi/{$youtube}/hqdefault.jpg" : null;
    }

    private static function dateModified($entry): ?string
    {
        $modified = $entry->lastModified();
        $published = Writing::date($entry);

        if (! $modified || ($published && $modified->lt($published))) {
            return null;
        }

        return $modified->toIso8601String();
    }
}
