<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

/**
 * A story told across several videos, e.g. "Edmonton is on the verge of an Elmdemic" and
 * its follow-up "Four new cases".
 *
 * Only one link is stored: a video's `follows_up` points at the one before it, and is set
 * on the newer video so publishing a sequel never means editing the original. The chain
 * both ways is worked out here: back through `follows_up`, forward by finding the video
 * that names this one.
 *
 * It's a line, not a tree. If two videos both follow up the same one, the earlier-published
 * one continues the thread; there's no branching to draw.
 */
class VideoThread
{
    /**
     * Oldest first. Empty when the video isn't part of a thread, so callers can treat
     * "fewer than two parts" as "nothing to show".
     *
     * @return Collection<int, Entry>
     */
    public static function for(Entry $entry): Collection
    {
        $parts = collect([$entry]);
        $seen = [$entry->id() => true];

        // The guards on $seen stop a mis-set pair that point at each other from looping.
        $current = $entry;
        while (($previous = self::previous($current)) && ! isset($seen[$previous->id()])) {
            $parts->prepend($previous);
            $seen[$previous->id()] = true;
            $current = $previous;
        }

        $current = $entry;
        while (($next = self::next($current)) && ! isset($seen[$next->id()])) {
            $parts->push($next);
            $seen[$next->id()] = true;
            $current = $next;
        }

        return $parts->count() > 1 ? $parts->values() : collect();
    }

    private static function previous(Entry $entry): ?Entry
    {
        $id = collect($entry->value('follows_up'))->first();

        if (! $id) {
            return null;
        }

        $previous = EntryFacade::find($id);

        return $previous?->published() ? $previous : null;
    }

    private static function next(Entry $entry): ?Entry
    {
        // Filtered in PHP, and read through collect(): the field may be stored as a bare id or
        // a one-item list, and the Stache index can't match either with a where clause.
        return EntryFacade::query()
            ->where('collection', 'videos')
            ->where('published', true)
            ->get()
            ->filter(fn (Entry $candidate) => collect($candidate->value('follows_up'))->first() === $entry->id())
            ->sortBy(fn (Entry $candidate) => Writing::date($candidate)?->getTimestamp() ?? 0)
            ->first();
    }
}
