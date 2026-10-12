<?php

namespace App\Tags;

use App\Support\Writing as WritingEntries;
use Statamic\Entries\Entry;
use Statamic\Tags\Tags;

/**
 * The /posts listing: posts plus videos with a companion article, newest first.
 *
 *     {{ writing }} {{ posts }} … {{ /posts }} {{ /writing }}
 *
 * Each item carries the handful of fields the listing uses, with `date` normalised across
 * the two collections and `is_video` so a row can say what it is. See App\Support\Writing.
 */
class Writing extends Tags
{
    public function index(): array
    {
        $entries = WritingEntries::entries();

        // `limit="3"` for the home page's latest list. Applied before augmenting, so the
        // home page doesn't pay for the hundred posts it isn't showing.
        if ($limit = $this->params->int('limit')) {
            $entries = $entries->take($limit);
        }

        return [
            'posts' => $entries
                ->map(fn (Entry $entry) => [
                    ...$entry->toAugmentedArray(['title', 'url', 'topics', 'og_description']),
                    'date' => WritingEntries::date($entry),
                    'is_video' => $entry->collectionHandle() === 'videos',
                    'image' => $this->image($entry),
                ])
                ->all(),
        ];
    }

    /**
     * A card image: the share card if there is one (landscape, and already made to sell the
     * piece), else a video's cover. Null when there's neither; the card goes without.
     */
    private function image(Entry $entry): ?string
    {
        foreach (['og_image', 'thumbnail'] as $field) {
            $asset = $entry->augmentedValue($field)?->value();
            $asset = is_iterable($asset) ? collect($asset)->first() : $asset;

            if ($asset) {
                return $asset->url();
            }
        }

        return null;
    }
}
