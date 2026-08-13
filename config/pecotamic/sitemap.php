<?php

return [
    'url' => 'sitemap.xml',
    'expire' => 60,
    'include_entries' => true,
    'include_terms' => true,
    'include_collection_terms' => true,

    'entry_types' => null,

    /**
     * Matched with preg_match against the entry's relative URL.
     *
     * `topics` exists to tag posts and to point a byline at the section of the site that
     * hosts the subject — /fringe for a Fringe post. The terms have no pages of their own
     * (the templates are gone, so they 404), and `include_terms` above would otherwise put
     * every one of them in the sitemap.
     */
    'exclude_urls' => [
        '~^/topics(/|$)~',
    ],

    /**
     * `exists` fringe pages are live so other pages can link them, but they're thin
     * near-empty pages that shouldn't be offered to crawlers. A static-method callable
     * rather than a closure so `config:cache` can serialize this file.
     */
    'filter' => [\App\Fringe\Reviews::class, 'sitemapFilter'],

    /**
     * The /fringe/{year}/reviews pages are controller routes, not entries, so they're
     * added explicitly in AppServiceProvider via Sitemap::addEntries().
     */
    'properties' => null,
];
