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
     */
    'exclude_urls' => [],

    'filter' => null,

    /**
     * The /fringe/{year}/reviews pages are controller routes, not entries, so they're
     * added explicitly in AppServiceProvider via Sitemap::addEntries().
     */
    'properties' => null,
];
