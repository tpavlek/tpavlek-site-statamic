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
        return [
            'posts' => WritingEntries::entries()
                ->map(fn (Entry $entry) => [
                    ...$entry->toAugmentedArray(['title', 'url', 'topics', 'og_description']),
                    'date' => WritingEntries::date($entry),
                    'is_video' => $entry->collectionHandle() === 'videos',
                ])
                ->all(),
        ];
    }
}
