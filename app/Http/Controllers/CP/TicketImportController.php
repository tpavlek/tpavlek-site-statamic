<?php

namespace App\Http\Controllers\CP;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;

class TicketImportController extends Controller
{
    /**
     * Map Fringe ticketing genre strings onto fringe_show_categories term slugs.
     */
    private const GENRE_MAP = [
        'musical' => 'musical',
        'music' => 'musical',
        'comedy' => 'comedy',
        'drama' => 'drama',
        'improv' => 'improv',
        'cabaret' => 'cabaret',
        'storytelling' => 'storytelling',
    ];

    public function __invoke(Request $request)
    {
        $request->validate([
            'url' => ['required', 'url', 'regex:#^https://tickets\.fringetheatre\.ca/event/#'],
        ]);

        $url = rtrim($request->input('url'), '/').'/';

        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return response()->json(['message' => 'The ticketing site did not respond. Try again in a moment.'], 422);
        }

        if (! $response->ok()) {
            return response()->json(['message' => 'The ticketing page could not be loaded ('.$response->status().').'], 422);
        }

        $html = $response->body();

        $title = $this->ogContent($html, 'og:title');

        if (! $title) {
            return response()->json(['message' => "Couldn't read show details from that page — the ticketing site may have changed. Fill the fields in manually."], 422);
        }

        $raw = [
            'title' => $title,
            'ticket_link' => $url,
            'recommendation' => 'watchlist',
        ];

        if ($instagram = $this->instagramHandle($html)) {
            $raw['artist'] = $this->findOrCreateArtist($instagram);
        }

        if ($categories = $this->categories($html)) {
            $raw['categories'] = $categories;
        }

        if ($posterPath = $this->downloadPoster($html, $title)) {
            $raw['poster'] = [$posterPath];
        }

        return response()->json(['fields' => $this->publishFields($raw)]);
    }

    /**
     * Convert raw stored-format values into publish-form values and meta, so the
     * fieldtype can drop them straight into the create form.
     */
    private function publishFields(array $raw): array
    {
        $blueprint = CollectionFacade::findByHandle('fringe_reviews')->entryBlueprint();

        $fields = [];

        foreach ($raw as $handle => $value) {
            $field = $blueprint->field($handle);

            if (! $field) {
                continue;
            }

            $processed = $field->newInstance()->setValue($value)->preProcess();

            $fields[$handle] = [
                'value' => $processed->value(),
                'meta' => $processed->fieldtype()->preload(),
            ];
        }

        return $fields;
    }

    private function ogContent(string $html, string $property): ?string
    {
        if (! preg_match('/property="'.preg_quote($property, '/').'" content="([^"]*)"/', $html, $m)) {
            return null;
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) ?: null;
    }

    /**
     * The artist entry for this instagram handle, created on the fly for new artists
     * (titled with the handle until a proper name is filled in).
     */
    private function findOrCreateArtist(string $instagram): string
    {
        $existing = EntryFacade::query()
            ->where('collection', 'artists')
            ->get()
            ->first(fn ($artist) => $artist->value('instagram') === $instagram);

        if ($existing) {
            return $existing->id();
        }

        $artist = EntryFacade::make()
            ->collection('artists')
            ->slug(Str::slug($instagram))
            ->data(['title' => $instagram, 'instagram' => $instagram]);

        $artist->save();

        return $artist->id();
    }

    private function instagramHandle(string $html): ?string
    {
        if (! preg_match('~instagram\.com/([A-Za-z0-9._]+)~', $html, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * The genre sits in the schedule list, marked by the icon_type icon,
     * e.g. "Music/Musical Theatre" or "Comedy".
     */
    private function categories(string $html): array
    {
        if (! preg_match('~icon_type\.svg.*?</span>\s*([^<]+)~s', $html, $m)) {
            return [];
        }

        $genre = strtolower(trim($m[1]));

        return collect(self::GENRE_MAP)
            ->filter(fn ($slug, $needle) => str_contains($genre, $needle))
            ->values()
            ->unique()
            ->all();
    }

    private function downloadPoster(string $html, string $title): ?string
    {
        $imageUrl = $this->ogContent($html, 'og:image');

        if (! $imageUrl) {
            return null;
        }

        try {
            $image = Http::timeout(15)->get($imageUrl);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }

        if (! $image->ok()) {
            return null;
        }

        $extension = strtolower(pathinfo(parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
        $path = 'fringe/'.Str::slug($title).'-poster.'.$extension;

        $container = AssetContainer::findByHandle('assets');
        $container->disk()->put($path, $image->body());

        return $path;
    }
}
