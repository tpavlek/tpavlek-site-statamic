<?php

namespace App\Schema;

use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Term as TermFacade;

/**
 * schema.org Review markup for a single Fringe show review.
 *
 * Built in PHP rather than inline in the Antlers template so that show titles are
 * escaped properly. The old inline version happened to survive because every title
 * uses apostrophes, but one double quote or angle bracket in a show name would have
 * produced invalid JSON on that page.
 *
 * Google will only show a review rich result if `itemReviewed` is a supported type
 * with its own required properties present. For an Event that means name, startDate
 * and location, none of which the old markup had. Festival dates come from the
 * fringe_festival term; if a year has no dates set, no markup is emitted at all,
 * since invalid structured data earns Search Console errors rather than rich results.
 */
class FringeReviewSchema
{
    public static function build($entry): ?string
    {
        $rating = $entry->value('stars');

        // A returning show with no fresh rating inherits the original review's, the same
        // way the page displays it ("★★★★★ at Fringe 2024") and the index sorts by it.
        if ($rating === null || $rating === '') {
            $rating = self::originalReview($entry)?->value('stars');
        }

        // Still nothing means it's a watchlist entry, which isn't a Review.
        if ($rating === null || $rating === '') {
            return null;
        }

        $festival = self::festival($entry);
        $startDate = $festival?->value('starts_at');

        if (! $startDate) {
            return null;
        }

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Review',
            'url' => $entry->absoluteUrl(),
            'dateModified' => $entry->lastModified()?->toIso8601String(),
            'author' => [
                '@type' => 'Person',
                'name' => 'Troy Pavlek',
                'url' => 'https://troypavlek.ca',
            ],
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => (string) $rating,
                // Zero stars is a real rating here: "I walked out."
                'worstRating' => '0',
                'bestRating' => '5',
            ],
            'reviewBody' => self::reviewBody($entry),
            'itemReviewed' => self::show($entry, $festival, $startDate),
        ]);

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT
        );
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
     * property is always present. Venue strings carry the number Fringers navigate
     * by, e.g. "34: The Faculty Events Centre".
     */
    private static function location($entry): array
    {
        return array_filter([
            '@type' => 'Place',
            'name' => $entry->value('venue') ?: 'Edmonton International Fringe Theatre Festival',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Edmonton',
                'addressRegion' => 'AB',
                'addressCountry' => 'CA',
            ],
        ]);
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
