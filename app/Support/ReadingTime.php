<?php

namespace App\Support;

/**
 * Minutes to read a Bard body, for the article header's byline.
 *
 * Counts the prose in paragraphs and headings, and also the prose inside sets: a quoted
 * source, a chat dialogue, a timeline or a punchline is read like any paragraph, and the
 * video articles lean on them heavily. Counting paragraphs alone put a two-minute DST
 * article at "1 min read". Images add looking time on Medium's scale (12 seconds for the
 * first, one less for each after, never under 3), since a screenshot of a post gets read too.
 *
 * Works on the raw node tree rather than the augmented value, so a listing that shows
 * read times doesn't pay for augmenting every set.
 */
class ReadingTime
{
    public const WORDS_PER_MINUTE = 230;

    /** Set fields that hold settings, ids or embed code rather than words. */
    private const NOT_PROSE = [
        'type', 'id', 'style', 'alignment', 'space', 'sticker', 'layout', 'size', 'card', 'mode',
        'code', 'instagram', 'source_url', 'end_mark', 'side', 'width', 'progress', 'total',
        'candidate', 'review', 'previous_reviews', 'recipe', 'author', 'include_content',
        'troy_stars', 'rhi_stars', 'anchor',
    ];

    /** Set fields that hold assets: images are looked at, portraits are not. */
    private const IMAGES = ['images', 'media', 'image'];
    private const PORTRAITS = ['photo', 'speaker_photo', 'troy_photo', 'rhi_photo'];

    public static function minutes($nodes): ?int
    {
        if (! is_array($nodes) || $nodes === []) {
            return null;
        }

        $words = 0;
        $images = 0;
        self::walk($nodes, $words, $images);

        $seconds = $words / self::WORDS_PER_MINUTE * 60;
        for ($i = 0; $i < $images; $i++) {
            $seconds += max(3, 12 - $i);
        }

        return max(1, (int) round($seconds / 60));
    }

    private static function walk(array $nodes, int &$words, int &$images): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'text') {
                $words += self::count($node['text'] ?? '');
            }

            if (($node['type'] ?? null) === 'set') {
                self::values($node['attrs']['values'] ?? [], $words, $images);
            }

            if (isset($node['content']) && is_array($node['content'])) {
                self::walk($node['content'], $words, $images);
            }
        }
    }

    private static function values(array $values, int &$words, int &$images): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::NOT_PROSE, true) || in_array($key, self::PORTRAITS, true)) {
                continue;
            }

            if (in_array($key, self::IMAGES, true)) {
                $images += count(array_filter((array) $value));
                continue;
            }

            if (is_array($value)) {
                // Grid rows (timeline entries, dialogue lines, stats), or a Bard field.
                self::isNodeList($value)
                    ? self::walk($value, $words, $images)
                    : self::values($value, $words, $images);
            } elseif (is_string($value)) {
                $words += self::count($value);
            }
        }
    }

    private static function isNodeList(array $value): bool
    {
        $first = reset($value);

        return is_array($first) && isset($first['type']) && ! isset($first['id']);
    }

    /** Words in a string that may be markdown: link targets aren't read. */
    private static function count(string $text): int
    {
        $text = preg_replace('/\]\([^)]*\)/', ']', $text);

        return str_word_count(strip_tags($text));
    }
}
