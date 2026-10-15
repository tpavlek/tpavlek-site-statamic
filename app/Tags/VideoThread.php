<?php

namespace App\Tags;

use App\Support\VideoThread as Thread;
use App\Support\Writing;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Tags\Tags;

/**
 * The story a video belongs to, for the video page. See App\Support\VideoThread.
 *
 *     {{ video_thread }} {{ if parts }} … {{ /if }} {{ /video_thread }}
 *
 * - `parts`: every video in the story, oldest first, each with `part`, `title`, `url`,
 *   `date` and `is_current`.
 * - `latest`: the newest part, but only when this page isn't it. That's the "there's an
 *   update" note, for readers who land on the original from search.
 * - `next`: the part after this one, with `cover` and `summary`, for the card that ends
 *   the article.
 *
 * A tag rather than a computed field so the lookup only runs on the page that shows it,
 * not every time a video is augmented in a listing.
 */
class VideoThread extends Tags
{
    public function index(): array
    {
        $entry = EntryFacade::find($this->params->get('id', $this->context->get('id')));

        $parts = $entry ? Thread::for($entry) : collect();

        if ($parts->isEmpty()) {
            return ['parts' => []];
        }

        $position = $parts->search(fn (Entry $part) => $part->id() === $entry->id());
        $latest = $parts->last();
        $next = $parts->get($position + 1);

        return [
            'parts' => $parts->map(fn (Entry $part, int $i) => [
                ...$this->card($part),
                'part' => $i + 1,
                'is_current' => $part->id() === $entry->id(),
            ])->all(),
            'total' => $parts->count(),
            'current_part' => $position + 1,
            'latest' => $latest->id() === $entry->id() ? null : $this->card($latest),
            'next' => $next ? [...$this->card($next), 'part' => $position + 2] : null,
        ];
    }

    private function card(Entry $entry): array
    {
        return [
            'title' => $entry->value('title'),
            'url' => $entry->url(),
            'date' => Writing::date($entry),
            'cover' => $entry->augmentedValue('thumbnail')?->value()?->url(),
            'summary' => $entry->augmentedValue('og_description')?->value(),
        ];
    }
}
