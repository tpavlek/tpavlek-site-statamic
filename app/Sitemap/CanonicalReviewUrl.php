<?php

namespace App\Sitemap;

use Statamic\Entries\Entry;

/**
 * Points the sitemap at the canonical URL for each year's Fringe reviews page.
 *
 * Statamic derives an entry's slug from its filename and ignores any `slug` key in the
 * front matter. The reviews pages are named fringe-2024-reviews.md and
 * fringe-2026-reviews.md, so Statamic routes them to /fringe-2024/fringe-2024-reviews
 * rather than the /fringe-2024/reviews that FringeController actually serves. Only 2025
 * lands correctly, and only because its file happens to be named reviews.md.
 *
 * Rewriting the last path segment covers every year without listing them, and the
 * filename-derived URLs 301 to the same place (see routes/web.php).
 *
 * This is a static method rather than a closure in the config file because closures
 * cannot be serialized, and `php artisan config:cache` fails on them at deploy time.
 * The package invokes the value directly, so it has to be a callable — a "Class::method"
 * string is both callable and serializable, which an invokable class name would not be.
 */
class CanonicalReviewUrl
{
    public static function handle($entry): ?array
    {
        if (! $entry instanceof Entry || $entry->template() !== 'fringe/index') {
            return null;
        }

        return [
            'loc' => preg_replace('~/[^/]+$~', '/reviews', $entry->absoluteUrl()),
        ];
    }
}
