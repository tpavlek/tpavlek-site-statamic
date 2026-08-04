<?php

namespace App\Http\Controllers;

use App\Og\CardRenderer;
use App\Support\BardSets;
use Illuminate\Http\Request;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Renders the OpenGraph card at /og-card so it can be looked at, tweaked and reloaded in a
 * browser like any other page.
 *
 * `php artisan og:card` screenshots this exact URL rather than reimplementing the layout,
 * so there is no way for the preview and the saved PNG to drift apart. That was the whole
 * problem with the first one of these, which existed only as an HTML file in /tmp and could
 * not be regenerated once the window was closed.
 *
 * Either pass the four text fields directly, or pass `entry` and let the card fill itself in
 * from an entry's own sharing copy.
 */
class OgCardController extends Controller
{
    /**
     * Headline length to font size, per format.
     *
     * `og` is tuned to the ~530px column left once three cards are fanned beside it, not to
     * the full 1200 — a headline sized off the card width overflows the moment art appears.
     * `square` stacks instead of splitting, so the headline gets the whole width and can run
     * larger at the same character count.
     */
    private const HEADLINE_TIERS = [
        'og' => [26 => 72, 44 => 60, 68 => 50],
        'square' => [26 => 92, 44 => 76, 68 => 62],
    ];

    private const HEADLINE_FLOOR = ['og' => 42, 'square' => 52];

    private const MAX_IMAGES = 3;

    public function __invoke(Request $request)
    {
        $defaults = $this->fromEntry($request->query('entry'));

        $format = $request->query('format', 'og');
        $format = isset(CardRenderer::FORMATS[$format]) ? $format : 'og';
        [$width, $height] = CardRenderer::FORMATS[$format];

        $headline = trim((string) ($request->query('headline') ?? $defaults['headline'] ?? ''));
        $images = $this->images($request, $defaults);

        return response()
            ->view('og.card', [
                'headline' => $headline ?: 'troypavlek.ca',
                'headlineSize' => $this->headlineSize($headline, $format),
                'format' => $format,
                'width' => $width,
                'height' => $height,
                'eyebrow' => trim((string) ($request->query('eyebrow') ?? $defaults['eyebrow'] ?? '')),
                'subhead' => trim((string) ($request->query('subhead') ?? $defaults['subhead'] ?? '')),
                'footnote' => trim((string) ($request->query('footnote') ?? $defaults['footnote'] ?? '')),
                'images' => $images,
                // Two cards need less room than three, and the copy should take the slack.
                'artWidth' => $images ? 300 + (count($images) - 1) * 112 : 0,
                // The square card has the full width to play with, so the fan runs bigger
                // and spreads further; these mirror the sizes in the stylesheet.
                'squareArtWidth' => $images ? 520 + (count($images) - 1) * 190 : 0,
                'pattern' => '/assets/fringe/fringe-doodles.svg',
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function headlineSize(string $headline, string $format): int
    {
        foreach (self::HEADLINE_TIERS[$format] as $limit => $size) {
            if (mb_strlen($headline) <= $limit) {
                return $size;
            }
        }

        return self::HEADLINE_FLOOR[$format];
    }

    /**
     * Local paths only. The rasteriser loads this page from the site itself, so a remote URL
     * would be a cross-origin fetch that may or may not have resolved by the time Chrome
     * takes the shot — and a card is not worth a flaky render.
     *
     * A bare path is an asset path (`fringe/og/foo.png`), matching how an entry stores one.
     * Start it with a slash to mean a site-root path instead.
     */
    private function images(Request $request, array $defaults): array
    {
        $images = $request->query('images');
        $images = $images === null
            ? ($defaults['images'] ?? [])
            : (is_array($images) ? $images : explode(',', (string) $images));

        return collect($images)
            ->map(fn ($path) => trim((string) $path))
            ->filter()
            ->reject(fn ($path) => (bool) preg_match('~^(https?:)?//~', $path))
            ->map(fn ($path) => str_starts_with($path, '/') ? $path : '/assets/'.$path)
            ->take(self::MAX_IMAGES)
            ->values()
            ->all();
    }

    /**
     * An entry already carries the copy this card wants: og_title is written to be a headline
     * and og_description to be a one-line pitch. Reusing them means the card and the share
     * preview text cannot say different things.
     */
    private function fromEntry(?string $id): array
    {
        if (! $id || ! ($entry = $this->findEntry($id))) {
            return [];
        }

        return [
            'headline' => $entry->value('og_title') ?: $entry->value('title'),
            'subhead' => $entry->value('og_description'),
            'eyebrow' => $this->eyebrow($entry),
            'footnote' => 'troypavlek.ca',
            'images' => $this->entryImages($entry),
        ];
    }

    private function findEntry(string $id)
    {
        if ($entry = EntryFacade::find($id)) {
            return $entry;
        }

        return EntryFacade::query()->get()->first(fn ($e) => $e->slug() === $id);
    }

    private function eyebrow($entry): string
    {
        $topics = collect((array) $entry->value('topics'));

        return $topics->contains('fringe') ? "Troy's Fringe Reviews" : 'troypavlek.ca';
    }

    /**
     * The images already in the post, in the order they appear — for a round-up that is the
     * show art, which is the most recognisable thing the card could possibly show.
     */
    private function entryImages($entry): array
    {
        return collect(BardSets::ofType($entry->value('content') ?? [], 'image'))
            ->flatMap(fn ($set) => (array) ($set['images'] ?? []))
            ->filter()
            ->unique()
            ->take(self::MAX_IMAGES)
            ->values()
            ->all();
    }

}
