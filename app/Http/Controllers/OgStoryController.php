<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;

/**
 * The 1080x1920 video thumbnail at /og-story, as a real page — the same iterate-in-a-browser
 * arrangement as /og-card and /og-carousel. `php artisan fringe:video-thumb` screenshots
 * this exact URL, so the preview and the saved PNG cannot drift.
 *
 * Query params, all optional:
 *
 *   day       "Day 1" — the number is enough, "1" becomes "Day 1"
 *   shows[]   a fringe_reviews slug, a fragment of a title, or "Title|4.5" to bypass the
 *             lookup entirely
 *   photo     asset path for the big framed photo (leading slash means site root)
 *   focus     CSS object-position for the photo's crop, like the OG card's portrait_focus
 *
 * Shows resolve against the review entries themselves so the stars on the thumbnail are the
 * stars on the site — hand-typed ratings drift the day a review gets revised. The "|" form
 * exists for a show without a review entry, or a deliberately shortened title.
 */
class OgStoryController extends Controller
{
    public function __invoke(Request $request)
    {
        $day = trim((string) $request->query('day', '1'));

        return response()->view('og.story', [
            'headline' => 'FRINGE',
            'day' => preg_match('~^\d~', $day) ? "Day {$day}" : $day,
            'eyebrow' => "Troy's Fringe Reviews",
            'footnote' => 'troypavlek.ca',
            'photo' => $this->localPath($request->query('photo')) ?? '/assets/fringe/fringe-with-atlas-2026.jpg',
            'focus' => $this->focus($request->query('focus')),
            'shows' => $this->shows($request),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @return array<int, array{title: string, stars: ?string}>
     */
    private function shows(Request $request): array
    {
        $shows = $request->query('shows');
        $shows = is_array($shows) ? $shows : array_filter([(string) $shows]);

        return collect($shows)
            ->map(fn ($show) => $this->show(trim((string) $show)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return ?array{title: string, stars: ?string}
     */
    private function show(string $show): ?array
    {
        if ($show === '') {
            return null;
        }

        if (str_contains($show, '|')) {
            [$title, $stars] = array_pad(explode('|', $show, 2), 2, '');

            return ['title' => trim($title), 'stars' => $this->label(trim($stars))];
        }

        if (! ($entry = $this->findReview($show))) {
            return ['title' => $show, 'stars' => null];
        }

        return [
            'title' => (string) $entry->value('title'),
            'stars' => $this->label((string) $entry->value('stars')),
        ];
    }

    /**
     * Slug match first, then a case-insensitive title fragment — so `shows[]=Weird Al
     * Karaoke` finds the entry without anyone typing the full official title. Raw values,
     * not augmented ones, for the usual reason (see yearReviews).
     */
    private function findReview(string $show): ?EntryContract
    {
        $reviews = EntryFacade::query()
            ->where('collection', 'fringe_reviews')
            ->get();

        return $reviews->first(fn ($e) => $e->slug() === $show)
            ?? $reviews
                ->filter(fn ($e) => str_contains(mb_strtolower((string) $e->value('title')), mb_strtolower($show)))
                // A fragment like "Sketchy Broads" matches past years' shows too; the
                // newest entry is the one a video posted today is about.
                ->sortByDesc(fn ($e) => $e->date())
                ->first();
    }

    /**
     * The same ★★★★½ shape the site's _stars partial renders, minus the ☆ padding — on a
     * dark card the hollow stars read as clutter rather than scale.
     */
    private function label(string $stars): ?string
    {
        if (! is_numeric($stars)) {
            return null;
        }

        $stars = (float) $stars;

        return str_repeat('★', (int) floor($stars)).(fmod($stars, 1) >= 0.5 ? '½' : '');
    }

    /**
     * Same rules as the OG card: local paths only, a bare path is an asset path.
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
     * Which part of the photo survives the crop, as a CSS object-position — validated
     * against a character allowlist because it lands in a stylesheet.
     */
    private function focus(?string $focus): string
    {
        $focus = trim((string) $focus);

        return preg_match('~^[a-z0-9%. ]{1,20}$~i', $focus) ? $focus : '25% 35%';
    }
}
