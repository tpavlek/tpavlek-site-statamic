<?php

namespace App\Http\Controllers;

use App\Fringe\Artists;
use App\Fringe\FestivalUrls;
use App\Schema\BreadcrumbSchema;
use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * Artist pages: /fringe/artists and /fringe/artists/{slug}.
 *
 * Controller routes rather than an entry route on the artists collection, because which
 * artists have pages is a rule about their reviews (see App\Fringe\Artists), not a flag on
 * the entry. An entry route would publish all 48, most of them titled with an Instagram
 * handle and holding one show.
 */
class FringeArtistController extends Controller
{
    public function index()
    {
        $artists = Artists::withPages()
            ->map(fn (EntryContract $artist) => [
                'title' => $artist->value('title'),
                'url' => Artists::url($artist),
                // works(), so the card doesn't read "Late Night Cabaret · Late Night
                // Cabaret · Late Night Cabaret".
                'reviews' => Artists::works($artist),
            ]);

        $title = 'Fringe Artists & Companies — Edmonton Fringe Reviews';
        $description = 'Every company I have reviewed more than once at the Edmonton Fringe, and what I made of each of their shows.';

        return (new \Statamic\View\View)
            ->template('fringe/artists')
            ->layout('layout')
            ->with([
                'artists' => $artists,
                'artist_count' => $artists->count(),
                'title' => $title,
                'og_title' => $title,
                'og_description' => $description,
                'og_image' => ['url' => FestivalUrls::absolute('/assets/og/fringe-reviews.png')],
                'canonical_url' => FestivalUrls::absolute('/fringe/artists'),
                'breadcrumbs' => BreadcrumbSchema::trailFor([
                    ['name' => 'Artists', 'path' => '/fringe/artists'],
                ]),
                'breadcrumb_schema' => BreadcrumbSchema::build(BreadcrumbSchema::trailFor([
                    ['name' => 'Artists', 'path' => '/fringe/artists'],
                ])),
            ]);
    }

    public function show(string $slug)
    {
        $artist = Artists::find($slug);

        abort_if(! $artist, 404);

        // works(), not reviews(): a returning show is one show, and listing it once per
        // staging made a company look twice as prolific as it is.
        $works = Artists::works($artist);
        $reviews = Artists::reviews($artist);
        $name = (string) $artist->value('title');
        $url = Artists::url($artist);

        $breadcrumbs = BreadcrumbSchema::trailFor([
            ['name' => 'Artists', 'path' => '/fringe/artists'],
            ['name' => $name, 'path' => $url],
        ]);

        $title = "{$name} — Edmonton Fringe Reviews";

        return (new \Statamic\View\View)
            ->template('fringe/artist')
            ->layout('layout')
            ->with([
                'artist' => $artist,
                'artist_name' => $name,
                'instagram' => $artist->value('instagram'),
                'website' => $artist->value('website'),
                'reviews' => $works,
                // Shows, not stagings. A company that brought one show back three years
                // running has brought one show, and "3 shows" would overstate them.
                'review_count' => $works->count(),
                // The range spans every staging, though — that's the artist's real history
                // at the festival, and it's what makes a returning show worth noting.
                'festival_range' => $this->festivalRange($reviews),
                'title' => $title,
                'og_title' => $title,
                'og_description' => $this->description($name, $works, $reviews),
                'og_image' => ['url' => FestivalUrls::absolute('/assets/og/fringe-reviews.png')],
                'canonical_url' => FestivalUrls::absolute($url),
                'breadcrumbs' => $breadcrumbs,
                'breadcrumb_schema' => BreadcrumbSchema::build($breadcrumbs),
                // Works, matching the visible list. Structured data that lists items the
                // page doesn't show is exactly the mismatch Google penalises.
                'structured_data' => $this->structuredData($name, $url, $works),
            ]);
    }

    /**
     * "2024–2026", or a single year when every show is from the same festival.
     */
    private function festivalRange(Collection $reviews): ?string
    {
        $years = $reviews
            ->map(fn (EntryContract $review) => $review->festival?->slug())
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($years->isEmpty()) {
            return null;
        }

        return $years->count() === 1
            ? $years->first()
            : $years->first().'–'.$years->last();
    }

    /**
     * The meta description names what the page can prove: how many shows, over what years,
     * and the best rating any of them earned. Vague descriptions were what the reviews index
     * was already losing clicks to.
     */
    private function description(string $name, Collection $works, Collection $reviews): string
    {
        // Every staging, so a rating earned by an earlier run still counts.
        $best = $reviews
            ->map(fn (EntryContract $review) => $review->augmentedValue('rating')->value()['value'] ?? null)
            ->filter(fn ($value) => $value !== null)
            ->max();

        $range = $this->festivalRange($reviews);
        $count = $works->count();
        $noun = $count === 1 ? 'show' : 'shows';

        $opening = "My reviews of {$count} {$noun} by {$name} at the Edmonton Fringe".($range ? ", {$range}." : '.');

        return $best !== null
            ? "{$opening} Rated up to {$best} out of five."
            : $opening;
    }

    /**
     * CollectionPage plus an ItemList of the shows — the same shape as the reviews index,
     * for the same reason: the full Review markup lives on each show's own page, and this is
     * a summary that points at them.
     *
     * Deliberately no Person or PerformingGroup for the artist. Nothing in the data says
     * whether a name is a solo performer or a company, and asserting the wrong one is worse
     * than asserting neither — it's a claim about a real person, made by a guess.
     */
    private function structuredData(string $name, string $url, Collection $reviews): string
    {
        $items = $reviews
            ->values()
            ->map(fn (EntryContract $review, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => FestivalUrls::absolute($review->url()),
                'name' => $review->value('title'),
            ])
            ->all();

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => "{$name} at the Edmonton Fringe",
            'url' => FestivalUrls::absolute($url),
            'inLanguage' => 'en-CA',
            'author' => [
                '@type' => 'Person',
                'name' => 'Troy Pavlek',
                'url' => 'https://troypavlek.ca',
            ],
            'mainEntity' => [
                '@type' => 'ItemList',
                'name' => "Shows by {$name}",
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ],
        ];

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRETTY_PRINT);
    }
}
