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

        $headline = $this->text($request, 'headline', $defaults);
        $portrait = $this->portrait($request);
        $images = $this->images($request, $defaults);

        return response()
            ->view('og.card', [
                'headline' => $headline ?: 'troypavlek.ca',
                'headlineSize' => $this->headlineSize($headline, $format),
                'format' => $format,
                'width' => $width,
                'height' => $height,
                'eyebrow' => $this->text($request, 'eyebrow', $defaults),
                'subhead' => $this->text($request, 'subhead', $defaults),
                'footnote' => $this->text($request, 'footnote', $defaults),
                'cta' => $this->text($request, 'cta', $defaults),
                'images' => $images,
                'portrait' => $portrait,
                // A photo is rarely square and the interesting part is rarely dead centre.
                'portraitFocus' => $this->focus($request->query('portrait_focus')),
                // Geometry for the fan of cards beside (or beneath) the copy.
                'fan' => $this->fan(count($images) + ($portrait ? 1 : 0), 300, self::FAN_STEP),
                // The square card stacks, so it has the full width to play with and the fan
                // runs bigger; these mirror the sizes in the stylesheet.
                'squareFan' => $this->fan(count($images) + ($portrait ? 1 : 0), 520, self::SQUARE_FAN_STEP),
                'pattern' => '/assets/fringe/fringe-doodles.svg',
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * A field the caller may override, including with nothing.
     *
     * Keyed on the parameter being *present* rather than on its value: Laravel's
     * ConvertEmptyStringsToNull middleware turns `?cta=` into null before it gets here, so a
     * `??` chain reads a deliberate blank as "not supplied" and helpfully puts the default
     * back. `?cta=` means no call to action; omitting it means take the entry's.
     */
    private function text(Request $request, string $key, array $defaults): string
    {
        if ($request->has($key)) {
            return trim((string) $request->query($key));
        }

        return trim((string) ($defaults[$key] ?? ''));
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
     * The artwork fanned beside the copy. A bare path is an asset path
     * (`fringe/og/foo.png`), matching how an entry stores one; start it with a slash to mean
     * a site-root path instead.
     */
    /**
     * How far each card in the fan is offset from the one behind it, by how many there are.
     *
     * A fourth card is tighter than three so the fan grows by less than a full card width —
     * see `bleed` below for where that growth goes.
     */
    private const FAN_STEP = [3 => 112, 4 => 96];

    private const SQUARE_FAN_STEP = [3 => 190, 4 => 150];

    /**
     * The fan's geometry: how wide it is, how far apart the cards sit, and how far it hangs
     * off the right edge of the card.
     *
     * The overhang is the point, and it has to be a negative *margin-right*. A negative
     * margin-left does nothing here: the copy beside it is `flex: 1 1 auto`, so the flex
     * algorithm hands the copy whatever the margin gives back and the fan lands in exactly
     * the same place. Shrinking the fan's outer width from the right is what actually moves
     * it, and lets it run past the padding.
     *
     * The layout reserves room for three cards. A fourth grows the fan by `width - reserved`,
     * and the whole of that growth is pushed off the right edge — so the fan's left edge
     * stays exactly where a three-card fan's would be, the headline keeps the breathing room
     * it had, and the last card's corner is clipped by the canvas. Which reads as deliberate,
     * because it is.
     */
    private function fan(int $count, int $card, array $steps): array
    {
        if ($count < 1) {
            return ['width' => 0, 'step' => 0, 'overhang' => 0];
        }

        $step = $steps[min($count, max(array_keys($steps)))] ?? $steps[max(array_keys($steps))];
        $width = $card + ($count - 1) * $step;
        $reserved = $card + (min($count, 3) - 1) * ($steps[3] ?? $step);

        return [
            'width' => $width,
            'step' => $step,
            'overhang' => max(0, $width - $reserved),
        ];
    }

    /**
     * A photo of a person, laid on top of the fan.
     *
     * Show art says what the reviews are about; a face says who is doing the reviewing, and
     * on a card for a page that lives or dies on whether you trust the reviewer, that is
     * worth one of the three slots rather than an extra one — a fourth card would squeeze the
     * headline column past the point where it reads.
     */
    private function portrait(Request $request): ?string
    {
        return $this->localPath($request->query('portrait'));
    }

    /**
     * Which part of the photo survives the square crop, as a CSS object-position. Defaults
     * to the middle, which is where a portrait's subject usually is and where a candid
     * snapshot's usually isn't.
     */
    private function focus(?string $focus): string
    {
        $focus = trim((string) $focus);

        return preg_match('~^[a-z0-9%. ]{1,20}$~i', $focus) ? $focus : 'center';
    }

    private function images(Request $request, array $defaults): array
    {
        $images = $request->query('images');
        $images = $images === null
            ? ($defaults['images'] ?? [])
            : (is_array($images) ? $images : explode(',', (string) $images));

        return collect($images)
            ->map(fn ($path) => $this->localPath($path))
            ->filter()
            ->take(self::MAX_IMAGES)
            ->values()
            ->all();
    }

    /**
     * Local paths only, and a bare path is an asset path. The card is rasterised from the
     * site itself, so a remote URL would be a cross-origin fetch that may or may not have
     * resolved by the time Chrome takes the shot — and a card is not worth a flaky render.
     */
    private function localPath($path): ?string
    {
        $path = trim((string) $path);

        if ($path === '' || preg_match('~^(https?:)?//~', $path)) {
            return null;
        }

        return str_starts_with($path, '/') ? $path : '/assets/'.$path;
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
            'cta' => $this->cta($entry),
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

    /**
     * A card is a link, so it should say what following it gets you. A round-up promises a
     * list; anything else just promises the post, and claiming otherwise on the one thing
     * people see before they click is the kind of small lie that costs trust.
     */
    private function cta($entry): string
    {
        $isList = BardSets::ofType($entry->value('content') ?? [], 'show') !== [];

        return $isList ? 'Read the full list →' : 'Read the post →';
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
