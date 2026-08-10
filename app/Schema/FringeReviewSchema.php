<?php

namespace App\Schema;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Term as TermFacade;

/**
 * schema.org markup for a single Fringe show page.
 *
 * Built in PHP rather than inline in the Antlers template so that show titles are
 * escaped properly. The old inline version happened to survive because every title
 * uses apostrophes, but one double quote or angle bracket in a show name would have
 * produced invalid JSON on that page.
 *
 * Three shapes, depending on how much Troy can actually vouch for:
 *
 *   rated               Review, with reviewRating. Eligible for Google's review snippet.
 *   recommended, unrated  Recommendation — a schema.org subtype of Review meaning
 *                       "suggests or proposes something as the best option". These are
 *                       shows Troy vouches for without having seen this staging, so
 *                       inventing a star value would be a lie. Google doesn't surface
 *                       Recommendation as a rich result, but it's the honest type and
 *                       machine-readable to everything that isn't Google.
 *   otherwise           the bare TheaterEvent, no review wrapper.
 *
 * Every shape carries the TheaterEvent, which is itself eligible for Google's event
 * rich result — so a watchlist show is no longer a page with no structured data at all.
 *
 * Google requires reviewRating on a Review *unless* the markup carries both an author
 * and a review date, which is why datePublished is not optional here. It comes from the
 * entry's date, which is why the collection is dated. Festival dates come from the
 * fringe_festival term; a year with no dates emits nothing, since invalid structured
 * data earns Search Console errors rather than rich results.
 */
class FringeReviewSchema
{
    public static function build($entry): ?string
    {
        $festival = self::festival($entry);
        $startDate = $festival?->value('starts_at');

        if (! $startDate) {
            return null;
        }

        $show = self::show($entry, $festival, $startDate);
        $rating = self::rating($entry);

        $data = match (true) {
            $rating !== null => self::review($entry, $show, $rating),
            self::isRecommended($entry) => self::recommendation($entry, $show),
            default => ['@context' => 'https://schema.org'] + $show,
        };

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );
    }

    private static function review($entry, array $show, string $rating): array
    {
        return array_filter(self::reviewBase('Review', $entry, $show) + [
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => $rating,
                // Zero stars is a real rating here: "I walked out."
                'worstRating' => '0',
                'bestRating' => '5',
            ],
        ]);
    }

    /**
     * A show Troy endorses without a rating of his own — he's seen the company elsewhere,
     * or trusts the people who have. No reviewRating, deliberately: there is no rating.
     */
    private static function recommendation($entry, array $show): array
    {
        return array_filter(self::reviewBase('Recommendation', $entry, $show) + [
            'category' => 'Recommended',
        ]);
    }

    private static function reviewBase(string $type, $entry, array $show): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'url' => $entry->absoluteUrl(),
            // Required by Google whenever reviewRating is absent, so always emitted.
            'datePublished' => $entry->date()?->toDateString(),
            'dateModified' => self::dateModified($entry),
            'author' => [
                '@type' => 'Person',
                'name' => 'Troy Pavlek',
                'url' => 'https://troypavlek.ca',
            ],
            'reviewBody' => self::reviewBody($entry),
            'itemReviewed' => $show,
        ];
    }

    /**
     * Omitted when it would predate publication. Entry dates are the festival day the show
     * was seen, while lastModified is when the file was last touched, and for reviews
     * written up before their festival opened the second can precede the first. A review
     * modified before it was published is incoherent, and dateModified is optional.
     */
    private static function dateModified($entry): ?string
    {
        $modified = $entry->lastModified();
        $published = $entry->date();

        if (! $modified || ($published && $modified->lt($published))) {
            return null;
        }

        return $modified->toIso8601String();
    }

    /**
     * The star rating as a string, or null when there isn't one. A returning show with no
     * fresh rating inherits the original review's, the same way the page displays it
     * ("★★★★★ at Fringe 2024") and the index sorts by it.
     */
    private static function rating($entry): ?string
    {
        $rating = $entry->value('stars');

        if ($rating === null || $rating === '') {
            $rating = self::originalReview($entry)?->value('stars');
        }

        return ($rating === null || $rating === '') ? null : (string) $rating;
    }

    private static function isRecommended($entry): bool
    {
        $value = $entry->value('recommendation');
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return $value === 'recommended';
    }

    private static function show($entry, $festival, string $startDate): array
    {
        return array_filter([
            '@type' => 'TheaterEvent',
            'name' => $entry->value('title'),
            'startDate' => $startDate,
            'endDate' => $festival?->value('ends_at'),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => self::location($entry),
            'performer' => self::performer($entry),
            'image' => self::poster($entry),
            'offers' => self::offers($entry, $festival),
        ]);
    }

    /**
     * The show's own venue when it's known, otherwise the festival's city, so the
     * property is always present. The name carries the number Fringers navigate by,
     * e.g. "34: The Faculty Events Centre".
     */
    private static function location($entry): array
    {
        $venue = self::venue($entry);

        // The stored address keeps wayfinding parentheticals like "(NW corner of Arts
        // Barn)" for readers; streetAddress is machine-readable, so those come off here.
        $street = $venue?->value('address');
        $street = $street ? trim(preg_replace('/\s*\(.*\)/', '', $street)) : null;

        return array_filter([
            '@type' => 'Place',
            'name' => self::venueName($venue) ?: 'Edmonton International Fringe Theatre Festival',
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $street,
                'addressLocality' => 'Edmonton',
                'addressRegion' => 'AB',
                'addressCountry' => 'CA',
            ]),
        ]);
    }

    private static function venue($entry)
    {
        $id = $entry->value('venue');
        $id = is_array($id) ? ($id[0] ?? null) : $id;

        return $id ? EntryFacade::find($id) : null;
    }

    /**
     * "29: Strathcona High School" — the number and name are stored separately on the venue
     * entry, and joined by its display_name computed field.
     */
    private static function venueName($venue): ?string
    {
        if (! $venue) {
            return null;
        }

        $number = $venue->value('number');
        $name = $venue->value('title');

        return $number ? "{$number}: {$name}" : $name;
    }

    private static function performer($entry): ?array
    {
        $id = $entry->value('artist');
        $id = is_array($id) ? ($id[0] ?? null) : $id;

        if (! $id || ! ($artist = EntryFacade::find($id))) {
            return null;
        }

        $name = $artist->value('title');

        // The ticket importer creates artists titled with their Instagram handle until
        // a real name is filled in; a handle is not a performer name.
        if (! $name || $name === $artist->value('instagram')) {
            return null;
        }

        return ['@type' => 'PerformingGroup', 'name' => $name];
    }

    private static function poster($entry): ?string
    {
        $poster = $entry->augmentedValue('poster')?->value();
        $poster = is_iterable($poster) ? collect($poster)->first() : $poster;

        return $poster?->absoluteUrl();
    }

    /**
     * Only while the festival's ticket links are still live; past years point at dead
     * pages, and advertising a dead offer is worse than advertising none.
     */
    private static function offers($entry, $festival): ?array
    {
        if (! $festival?->value('tickets_available') || ! ($link = $entry->value('ticket_link'))) {
            return null;
        }

        return [
            '@type' => 'Offer',
            'url' => $link,
            'availability' => 'https://schema.org/InStock',
        ];
    }

    private static function reviewBody($entry): ?string
    {
        $content = (string) $entry->augmentedValue('content');

        // A returning show with no fresh write-up inherits the original review's text,
        // the same fallback the template uses for the visible copy.
        if (trim(strip_tags($content)) === '' && ($original = self::originalReview($entry))) {
            $content = (string) $original->augmentedValue('content');
        }

        // Close block tags first, or paragraphs concatenate ("show.The density...").
        $text = preg_replace('~</(p|div|li|h[1-6]|blockquote)\s*>~i', ' ', $content);
        // Bard augments to HTML, so apostrophes arrive as entities and would otherwise
        // be published literally as "there&#039;s".
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $text !== '' ? $text : null;
    }

    private static function originalReview($entry): ?EntryContract
    {
        $id = $entry->value('original_review');
        $id = is_array($id) ? ($id[0] ?? null) : $id;

        return $id ? EntryFacade::find($id) : null;
    }

    private static function festival($entry)
    {
        $slug = $entry->value('festival');
        $slug = is_array($slug) ? ($slug[0] ?? null) : $slug;

        return $slug ? TermFacade::find("fringe_festival::{$slug}") : null;
    }
}
