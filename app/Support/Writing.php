<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Everything on the site that reads as a post: the posts themselves, plus any video that has
 * a companion article.
 *
 * A video with an article is written up in the video entry rather than as a separate post,
 * so the video stays one entry that Fringe reviews, endorsements and posts can all point at.
 * The cost is that it isn't in the posts collection, so anything that lists "writing" — the
 * /posts index and /feed.xml — has to ask here instead of querying posts directly.
 *
 * The two collections disagree on where the date lives: posts are a dated collection, videos
 * keep a `publish_date` field. date() is the one place that knows that.
 */
class Writing
{
    /**
     * @return Collection<int, Entry> newest first
     */
    public static function entries(): Collection
    {
        $posts = EntryFacade::query()
            ->where('collection', 'posts')
            ->where('published', true)
            ->get();

        // Filtered in PHP: "has an article" is a non-empty Bard array, which the Stache
        // index can't express as a where clause.
        $videos = EntryFacade::query()
            ->where('collection', 'videos')
            ->where('published', true)
            ->get()
            ->filter(fn (Entry $entry) => self::hasArticle($entry));

        return $posts->concat($videos)
            ->sortByDesc(fn (Entry $entry) => self::date($entry)?->getTimestamp() ?? 0)
            ->values();
    }

    public static function hasArticle(Entry $entry): bool
    {
        return $entry->collectionHandle() === 'videos'
            && ! empty($entry->value('article'));
    }

    public static function date(Entry $entry): ?CarbonInterface
    {
        if ($entry->collectionHandle() === 'videos') {
            return $entry->augmentedValue('publish_date')->value();
        }

        return $entry->date();
    }
}
