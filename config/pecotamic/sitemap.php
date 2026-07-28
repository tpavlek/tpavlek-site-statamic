<?php

use Statamic\Entries\Entry;

return [
    'url' => 'sitemap.xml',
    'expire' => 60,
    'include_entries' => true,
    'include_terms' => true,
    'include_collection_terms' => true,

    'entry_types' => null,

    /**
     * Matched with preg_match against the entry's relative URL.
     */
    'exclude_urls' => [
        // Empty structural parents that exist only to nest the reviews pages under
        // /fringe-YYYY/. They have a title but no body content.
        '~^/fringe-\d{4}$~',
    ],

    'filter' => null,

    /**
     * Statamic derives an entry's slug from its filename, ignoring any `slug` key in
     * the front matter. The reviews pages are named fringe-2024-reviews.md and
     * fringe-2026-reviews.md, so Statamic routes them to /fringe-2024/fringe-2024-reviews
     * rather than the /fringe-2024/reviews the FringeController actually serves. Only
     * 2025 lands correctly, and only because its file happens to be named reviews.md.
     *
     * Rather than depend on filenames, point every fringe/index entry at the canonical
     * controller route. Future years are covered without another change here, and the
     * filename-derived URLs 301 to the same place (see routes/web.php).
     */
    'properties' => static function ($entry): ?array {
        if (! $entry instanceof Entry || $entry->template() !== 'fringe/index') {
            return null;
        }

        return [
            'loc' => preg_replace('~/[^/]+$~', '/reviews', $entry->absoluteUrl()),
        ];
    },
];
