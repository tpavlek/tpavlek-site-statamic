<?php

namespace App\Fringe;

use Statamic\Facades\Term as TermFacade;
use Statamic\Taxonomies\LocalizedTerm;

/**
 * Where a festival year's reviews live.
 *
 * The current festival renders at /fringe/reviews and only there; its /fringe/{year}/reviews
 * URL redirects. Every other year renders at its own URL. That rule decides canonical tags,
 * sitemap entries and every internal link, so it lives here once rather than being restated
 * at each call site — the failure mode is a link or a canonical quietly pointing at the
 * redirect, which is exactly the signal dilution the structure exists to prevent.
 *
 * Templates reach this through the {{ fringe:reviews_url }} tag in App\Tags\Fringe.
 */
class FestivalUrls
{
    /**
     * The permanent, year-agnostic URL. This is the one Troy shares publicly and the one
     * the head terms are meant to rank.
     */
    public const EVERGREEN = '/fringe/reviews';

    /**
     * Canonicals have to name the production origin even when rendered somewhere else, or a
     * preview host would publish canonicals pointing at itself.
     */
    private const ORIGIN = 'https://troypavlek.ca';

    private static ?string $currentSlug = null;

    /**
     * The newest festival by year. Creating a fringe_festival term is what makes a new year
     * current, which is what flips every URL below.
     */
    public static function currentSlug(): string
    {
        return self::$currentSlug ??= TermFacade::query()
            ->where('taxonomy', 'fringe_festival')
            ->get()
            ->sortByDesc(fn (LocalizedTerm $term) => (int) $term->slug())
            ->first()
            ?->slug() ?? '2026';
    }

    public static function isCurrent(?string $year): bool
    {
        return $year === null || $year === self::currentSlug();
    }

    /**
     * The URL that actually serves a given year's reviews. Never returns a URL that
     * redirects, which is the entire point.
     */
    public static function reviews(?string $year = null): string
    {
        return self::isCurrent($year)
            ? self::EVERGREEN
            : "/fringe/{$year}/reviews";
    }

    public static function absoluteReviews(?string $year = null): string
    {
        return self::ORIGIN.self::reviews($year);
    }

    /**
     * Only for tests, which change which term is newest between cases within one process.
     */
    public static function flush(): void
    {
        self::$currentSlug = null;
    }
}
