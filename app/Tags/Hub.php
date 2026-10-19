<?php

namespace App\Tags;

use App\Support\Hubs;
use App\Support\Writing;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Tags\Tags;

/**
 * Hubs, for the templates. See App\Support\Hubs.
 *
 *     {{ hub:page }}  on a hub's own page: `photo`, `items`, `record`, `stats`, `total`
 *     {{ hub:for }}   on a post or video: one row per hub it belongs to, each with `title`,
 *                     `url`, `photo`, `total` and `more` (the newest other pieces). `total`, not
 *                     `count`: Antlers keeps `count` for the loop index.
 *
 * A tag rather than computed fields so the coverage lookup only runs on the page that shows
 * it, as with {{ video_thread }}.
 */
class Hub extends Tags
{
    public function page(): array
    {
        $hub = $this->current();

        if (! $hub) {
            return [];
        }

        $items = Hubs::items($hub);
        $record = Hubs::record($hub);

        return [
            'schema' => $this->schema($hub, $items),
            'photo' => $this->photo($hub),
            'total' => Hubs::coverage($hub)->count(),
            'items' => $items->map(fn (array $item) => [
                ...$this->card($item['entry']),
                'earlier' => $item['earlier']->map(fn (Entry $part) => $this->card($part))->all(),
            ])->all(),
            'record' => $record->all(),
            'stats' => $record->isEmpty() ? null : [
                'motions' => $record->count(),
                'moved' => $record->where('moved', true)->count(),
                'for' => $record->where('vote', 'for')->count(),
                'against' => $record->where('vote', 'against')->count(),
                'against_own' => $record->where('against_own', true)->count(),
            ],
        ];
    }

    /**
     * An empty string, not an empty array, when there are no hubs: Antlers renders a pair
     * tag's contents once against the page's own context for `[]`, so every hub-less piece
     * showed "Part of the <its own title> hub".
     */
    public function for(): array|string
    {
        $entry = $this->current();

        if (! $entry) {
            return '';
        }

        $hubs = Hubs::for($entry)->map(function (Entry $hub) use ($entry) {
            $coverage = Hubs::coverage($hub);

            return [
                'title' => $hub->value('title'),
                'url' => $hub->url(),
                'photo' => $this->photo($hub),
                'total' => $coverage->count(),
                'more' => $coverage
                    ->reject(fn (Entry $other) => $other->id() === $entry->id())
                    ->take((int) $this->params->get('more', 3))
                    ->map(fn (Entry $other) => $this->card($other))
                    ->values()
                    ->all(),
            ];
        })->all();

        return $hubs ?: '';
    }

    /** A CollectionPage whose list is the coverage, so search engines read the hub as an index. */
    private function schema(Entry $hub, $items): string
    {
        $url = $hub->absoluteUrl();

        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $url,
            'url' => $url,
            'name' => $hub->value('title'),
            'description' => $hub->value('og_description') ?: strip_tags((string) $hub->value('intro')),
            'author' => ['@type' => 'Person', 'name' => 'Troy Pavlek', 'url' => url('/')],
            'mainEntity' => [
                '@type' => 'ItemList',
                'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
                'numberOfItems' => $items->count(),
                'itemListElement' => $items->values()->map(fn (array $item, int $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => $item['entry']->absoluteUrl(),
                    'name' => $item['entry']->value('title'),
                ])->all(),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    }

    private function current(): ?Entry
    {
        return EntryFacade::find($this->params->get('id', $this->context->get('id')));
    }

    private function photo(Entry $hub): ?string
    {
        $person = ($id = collect($hub->value('person'))->first()) ? EntryFacade::find($id) : null;

        return self::assetUrl($hub, 'photo') ?? ($person ? self::assetUrl($person, 'photo') : null);
    }

    /** An assets field's first file, however the field's max_files makes it augment. */
    private static function assetUrl(Entry $entry, string $field): ?string
    {
        $value = $entry->augmentedValue($field)?->value();

        if ($value instanceof \Statamic\Contracts\Query\Builder || $value instanceof \Illuminate\Support\Collection) {
            $value = $value->first();
        }

        return $value?->url();
    }

    private function card(Entry $entry): array
    {
        $isVideo = $entry->collectionHandle() === 'videos';

        $cover = $isVideo
            ? (self::assetUrl($entry, 'thumbnail')
                ?? (($youtube = $entry->value('youtube')) ? "https://i.ytimg.com/vi/{$youtube}/hqdefault.jpg" : null))
            : self::assetUrl($entry, 'og_image');

        return [
            'title' => $entry->value('title'),
            'url' => $entry->url(),
            'date' => Writing::date($entry),
            'is_video' => $isVideo,
            'cover' => $cover,
            // Some videos' descriptions are only a source link, which reads as noise in a list.
            'summary' => preg_match('~^\s*https?://\S+\s*$~', $summary = (string) $entry->augmentedValue('og_description')?->value()) ? null : ($summary ?: null),
        ];
    }
}
