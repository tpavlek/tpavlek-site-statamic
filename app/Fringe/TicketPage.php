<?php

namespace App\Fringe;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry as EntryFacade;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Reading a show off tickets.fringetheatre.ca.
 *
 * Extracted from App\Http\Controllers\CP\TicketImportController, which used to own all of
 * this privately. Two callers now: that controller, for the paste-a-link field on a review
 * Troy is creating by hand, and App\Console\Commands\ImportFringeLineup, which walks the
 * whole festival. Both want identical parsing — a lineup import that read venues or artists
 * differently from the manual one would quietly produce a second set of venue entries.
 *
 * Deliberately excludes `recommendation`: the manual import means "a show I intend to see"
 * (watchlist), the bulk import means "this exists" (pending), and that difference is the
 * caller's to state.
 */
class TicketPage
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

    public const HOST = 'https://tickets.fringetheatre.ca';

    /**
     * Every event on the festival's listing page, as absolute event URLs.
     *
     * The listing is a single page with no pagination — all 212 shows for 2026 — so this is
     * one request rather than a crawl.
     *
     * @return array<int, string>
     */
    public static function lineup(): array
    {
        $response = Http::timeout(30)->get(self::HOST.'/events/');

        if (! $response->ok()) {
            return [];
        }

        preg_match_all('~/event/(\d+:\d+)~', $response->body(), $matches);

        return collect($matches[1])
            ->unique()
            ->map(fn (string $id) => self::HOST.'/event/'.$id.'/')
            ->values()
            ->all();
    }

    /**
     * The stable part of an event URL — `601:7533`. Used to tell whether a show already has
     * an entry, since titles change between years and URLs pick up trailing slashes.
     */
    public static function eventId(?string $url): ?string
    {
        if (! $url || ! preg_match('~/event/(\d+:\d+)~', $url, $m)) {
            return null;
        }

        return $m[1];
    }

    public static function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return null;
        }

        return $response->ok() ? $response->body() : null;
    }

    /**
     * The review fields this page yields, in stored format.
     *
     * `$withPoster` is off for callers that don't want to pull an image per show — the bulk
     * import of ~200 shows makes that the slowest and most failure-prone part of the run.
     *
     * @return array<string, mixed>|null  null when the page can't be read at all
     */
    public static function fields(string $html, string $url, bool $withPoster = true, bool $create = true): ?array
    {
        $title = self::ogContent($html, 'og:title');

        if (! $title) {
            return null;
        }

        $fields = [
            'title' => $title,
            'ticket_link' => $url,
        ];

        if ($instagram = self::instagramHandle($html)) {
            if ($id = self::findOrCreateArtist($instagram, $create)) {
                $fields['artist'] = $id;
            }
        }

        if ($categories = self::categories($html)) {
            $fields['categories'] = $categories;
        }

        if ($minutes = self::durationMinutes($html)) {
            $fields['duration'] = $minutes;
        }

        if ($venue = self::venue($html, $create)) {
            $fields['venue'] = $venue;
        }

        if ($withPoster && ($posterPath = self::downloadPoster($html, $title))) {
            $fields['poster'] = [$posterPath];
        }

        return $fields;
    }

    /**
     * Fold a name to something comparable: case and accents removed.
     *
     * The ticket site spells the same venue two ways — "SERVUS Credit Union Theatre" for most
     * shows and "SERVUS Credit Union Théâtre" for the ones at La Cité — and an exact match
     * made a second entry for the same room, which would split its venue notes in half.
     * Instagram handles are likewise case-insensitive, and a capitalised one is how the
     * duplicate Top Bunk Theatre artist came about.
     */
    private static function fold(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    public static function ogContent(string $html, string $property): ?string
    {
        if (! preg_match('/property="'.preg_quote($property, '/').'" content="([^"]*)"/', $html, $m)) {
            return null;
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) ?: null;
    }

    /**
     * The artist entry for this instagram handle, created on the fly for new artists
     * (titled with the handle until a proper name is filled in — see App\Fringe\Artists,
     * which refuses to give a handle-titled artist a page).
     */
    public static function findOrCreateArtist(string $instagram, bool $create = true): ?string
    {
        $existing = EntryFacade::query()
            ->where('collection', 'artists')
            ->get()
            ->first(fn ($artist) => self::fold((string) $artist->value('instagram')) === self::fold($instagram));

        if ($existing) {
            return $existing->id();
        }

        if (! $create) {
            return null;
        }

        $artist = EntryFacade::make()
            ->collection('artists')
            ->slug(Str::slug($instagram))
            ->data(['title' => $instagram, 'instagram' => $instagram]);

        $artist->save();

        return $artist->id();
    }

    public static function instagramHandle(string $html): ?string
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
    public static function categories(string $html): array
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

    /**
     * The running time sits in the same schedule list as the genre, marked by the
     * icon_duration icon, e.g. "60 minutes".
     */
    public static function durationMinutes(string $html): ?int
    {
        if (! preg_match('~icon_duration\.svg.*?</span>\s*(\d+)\s*minutes~s', $html, $m)) {
            return null;
        }

        return (int) $m[1] ?: null;
    }

    /**
     * The venue sits in the same schedule list as the genre, marked by the subvenue
     * icon, e.g. "34: The Faculty Events Centre". Returns the id of the matching venue
     * entry, created on the fly the first time a venue shows up, so that venue notes
     * written once apply to every show playing there.
     */
    public static function venue(string $html, bool $create = true): ?string
    {
        if (! preg_match('~subvenue\.svg[^>]*>\s*</span>\s*([^<]+)~', $html, $m)) {
            return null;
        }

        $raw = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));

        if ($raw === '') {
            return null;
        }

        // The number is stored separately so renumbering a venue, or a new sponsor in its
        // name, doesn't strand the notes on an orphaned entry.
        $number = null;
        $name = $raw;

        if (preg_match('/^\s*(\d+)\s*:\s*(.+)$/', $raw, $parts)) {
            [, $number, $name] = $parts;
            $name = trim($name);
        }

        return self::findOrCreateVenue($name, $number, $create);
    }

    public static function findOrCreateVenue(string $name, ?string $number, bool $create = true): ?string
    {
        $existing = EntryFacade::query()
            ->where('collection', 'venues')
            ->get()
            ->first(fn ($venue) => self::fold((string) $venue->value('title')) === self::fold($name));

        if ($existing) {
            return $existing->id();
        }

        if (! $create) {
            return null;
        }

        $venue = EntryFacade::make()
            ->collection('venues')
            ->slug(Str::slug($name))
            ->data(array_filter(['title' => $name, 'number' => $number]));

        $venue->save();

        return $venue->id();
    }

    public static function downloadPoster(string $html, string $title): ?string
    {
        $imageUrl = self::ogContent($html, 'og:image');

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
        $filename = Str::slug($title).'-poster.'.$extension;
        $path = 'fringe/'.$filename;

        $container = AssetContainer::findByHandle('assets');

        // Reuse the file only when it's byte-for-byte the poster we just fetched, i.e.
        // this show was imported before. Matching on the filename alone isn't enough:
        // a returning show keeps its title, so "Scratch" in 2026 would silently adopt
        // the 2025 poster. A same-named but different image falls through and Statamic
        // gives it a non-colliding name.
        if ($container->disk()->exists($path) && $container->disk()->get($path) === $image->body()) {
            // The file may predate this importer and not be in the container's cached
            // file listing yet; saving registers it.
            $existing = $container->asset($path) ?? tap($container->makeAsset($path))->save();

            return $existing->path();
        }

        $temp = tempnam(sys_get_temp_dir(), 'poster');
        file_put_contents($temp, $image->body());

        // Upload through the container rather than writing to the disk directly,
        // so the asset gets its meta file and lands in the container's cached file
        // listing. Without that, the asset doesn't "exist" yet and the assets
        // fieldtype silently drops the path when preprocessing it.
        // Statamic deletes the source file itself once the upload is written.
        $asset = $container->makeAsset($path)->upload(
            new UploadedFile($temp, $filename, null, null, true)
        );

        return $asset ? $asset->path() : null;
    }
}
