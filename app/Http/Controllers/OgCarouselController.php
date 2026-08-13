<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

/**
 * One slide of an Instagram carousel, as a real page — the same iterate-in-a-browser
 * arrangement as /og-card. Slides are defined in storage/app/private/og/carousel.json
 * (title card plus one card per timeslot); `php artisan fringe:carousel` screenshots each
 * /og-carousel/{slide} at 1080x1080. Edit the JSON, reload, reshoot.
 *
 * The JSON, not query params, because a slot slide is a nested list of shows — the flat
 * key=value shape /og-card reads would turn into a mini serialization format here.
 */
class OgCarouselController extends Controller
{
    public const PATH = 'og/carousel.json';

    public function __invoke(int $slide)
    {
        $slides = $this->slides();

        abort_unless(isset($slides[$slide]), 404);

        $data = $slides[$slide];
        $headline = $data['headline'] ?? '';

        return response()->view('og.carousel', [
            'type' => in_array($data['type'] ?? null, ['title', 'outro']) ? $data['type'] : 'slot',
            'banner' => $data['banner'] ?? null,
            'eyebrow' => $data['eyebrow'] ?? null,
            'headline' => $headline,
            // The slot names are short, so they can take the big size; the tiers only
            // matter for a long title-card headline.
            'headlineSize' => strlen($headline) <= 24 ? 96 : (strlen($headline) <= 44 ? 76 : 62),
            'subhead' => $data['subhead'] ?? null,
            'punchline' => $data['punchline'] ?? null,
            'footnote' => $data['footnote'] ?? null,
            'posters' => array_slice($data['posters'] ?? [], 0, 3),
            'shows' => array_slice($data['shows'] ?? [], 0, 3),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function slides(): array
    {
        if (! Storage::exists(self::PATH)) {
            return [];
        }

        $doc = json_decode(Storage::get(self::PATH), true) ?: [];

        // Top-level eyebrow/footnote apply to every slide unless the slide overrides them,
        // so the festival name isn't repeated four times in the file.
        return collect($doc['slides'] ?? [])
            ->map(fn (array $slide) => $slide + [
                'eyebrow' => $doc['eyebrow'] ?? null,
                'footnote' => $doc['footnote'] ?? null,
            ])
            ->values()
            ->all();
    }
}
