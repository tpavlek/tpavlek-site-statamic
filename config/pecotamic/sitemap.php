<?php

use App\Sitemap\CanonicalReviewUrl;

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
     * Points each year's reviews page at the URL the FringeController actually serves;
     * see the class for why that differs from the URL Statamic derives.
     *
     * Must stay a "Class::method" string, not a closure. Closures can't be serialized,
     * so `php artisan config:cache` fails on them at deploy time.
     */
    'properties' => CanonicalReviewUrl::class.'::handle',
];
