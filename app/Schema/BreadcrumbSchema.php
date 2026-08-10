<?php

namespace App\Schema;

use App\Fringe\FestivalUrls;

/**
 * BreadcrumbList markup for the Fringe pages, and the trail the visible breadcrumb renders.
 *
 * Google puts the trail in place of the raw URL in a result, and this is the machine-readable
 * half of fringe/_breadcrumbs — both come from one trail so the two can't drift apart.
 *
 * URLs come from FestivalUrls, so a year that redirects is never named here. A breadcrumb
 * pointing at a redirect is exactly the diluted signal that structure exists to prevent.
 */
class BreadcrumbSchema
{
    /**
     * One rung.
     *
     * Three fields where one address would do, each earning its place:
     *
     *   name   what the JSON-LD claims. Long and descriptive, because on ~200 show pages the
     *          middle rung is the anchor text pointing at the reviews index.
     *   path   what the visible link points at. Relative, so a crumb on a preview or local
     *          host doesn't walk the reader off to production the moment they click it.
     *   label  what the reader sees, defaulting to `name`. Splits from it only where the full
     *          name would read as clutter — see reviewsIndex().
     */
    private static function crumb(string $name, string $path, ?string $label = null): array
    {
        return [
            'name' => $name,
            'label' => $label ?? $name,
            'path' => $path,
            // Absolute, because schema.org `item` has to be, and because a canonical-adjacent
            // signal should always name the production origin rather than whatever host
            // happens to be rendering.
            'url' => FestivalUrls::absolute($path),
        ];
    }

    private static function hub(): array
    {
        return self::crumb('Fringe', '/fringe');
    }

    /**
     * The reviews index for a year.
     *
     * `$isCurrentPage` is what picks the label. On a show page this rung is a link and wants
     * its full keyword-carrying name. On the index itself it's the last rung, sitting a few
     * pixels above an <h1> that says exactly the same words — printing the long form twice in
     * a row is what made the trail read as clutter rather than as navigation.
     */
    private static function reviewsIndex(string $festivalSlug, bool $isCurrentPage): array
    {
        return self::crumb(
            "Edmonton Fringe Reviews ({$festivalSlug})",
            FestivalUrls::reviews($festivalSlug),
            $isCurrentPage ? "Reviews ({$festivalSlug})" : null,
        );
    }

    /**
     * A trail of the hub plus arbitrary rungs beneath it, each ['name' => ..., 'path' => ...].
     *
     * For the parts of the section that don't hang off a festival year — the artist pages —
     * so they get the same hub rung and the same shape without restating it.
     */
    public static function trailFor(array $rungs): array
    {
        return array_merge(
            [self::hub()],
            array_map(fn (array $rung) => self::crumb($rung['name'], $rung['path']), $rungs),
        );
    }

    /**
     * The trail for a year's reviews index. The last rung is the current page.
     */
    public static function forReviewsIndex(?string $festivalSlug): array
    {
        return array_values(array_filter([
            self::hub(),
            $festivalSlug ? self::reviewsIndex($festivalSlug, true) : null,
        ]));
    }

    /**
     * The trail for a single show page.
     *
     * A review with no festival term skips the middle rung rather than guessing one: a trail
     * with a wrong rung is worse than a short one.
     */
    public static function forReview($entry): array
    {
        $festivalSlug = $entry->festival?->slug();

        // Computed fields also run on the CP's create screen, where the entry has no title
        // or URL yet — no page exists, so no leaf rung either.
        $title = $entry->value('title');
        $url = $entry->url();

        return array_values(array_filter([
            self::hub(),
            $festivalSlug ? self::reviewsIndex($festivalSlug, false) : null,
            $title && $url ? self::crumb($title, $url) : null,
        ]));
    }

    public static function build(array $trail): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $crumb, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ], $trail, array_keys($trail)),
        ];

        // HEX_TAG so a show title containing "</script>" can't break out of the tag.
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );
    }
}
