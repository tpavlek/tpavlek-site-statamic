<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

/**
 * A hub gathers everything about one subject that keeps coming up: a person (Karen Principe)
 * or a beat (infill). It's for the reader who arrives wanting "all of Troy's coverage of X",
 * and for the reader on one of those pieces who wants the rest.
 *
 * A piece joins a hub one of two ways: it names the hub in its `hubs` field, or it carries
 * the hub's `topic`. The topic route is so a beat that already has a topic tag doesn't need
 * every entry tagged twice.
 *
 * Unlike a follow-up thread, a hub has no order or "part 2": its pieces are separate
 * incidents. Where some of them do form a thread, the hub shows the thread as one item.
 */
class Hubs
{
    /** @return Collection<int, Entry> the hubs a piece belongs to, in title order */
    public static function for(Entry $entry): Collection
    {
        $tagged = collect($entry->value('hubs'))->filter()->all();
        $topics = collect($entry->value('topics'))->filter()->all();

        return self::all()
            ->filter(fn (Entry $hub) => in_array($hub->id(), $tagged, true)
                || (($topic = self::topic($hub)) && in_array($topic, $topics, true)))
            ->values();
    }

    /** @return Collection<int, Entry> */
    public static function all(): Collection
    {
        return EntryFacade::query()
            ->where('collection', 'hubs')
            ->where('published', true)
            ->get()
            ->sortBy(fn (Entry $hub) => $hub->value('title'))
            ->values();
    }

    /**
     * Every published post and video in the hub, newest first. Videos without an article
     * count too: a hub is about the subject, and a short is still coverage.
     *
     * @return Collection<int, Entry>
     */
    public static function coverage(Entry $hub): Collection
    {
        $topic = self::topic($hub);

        return EntryFacade::query()
            ->whereIn('collection', ['posts', 'videos'])
            ->where('published', true)
            ->get()
            ->filter(fn (Entry $entry) => in_array($hub->id(), collect($entry->value('hubs'))->all(), true)
                || ($topic && in_array($topic, collect($entry->value('topics'))->all(), true)))
            ->sortByDesc(fn (Entry $entry) => Writing::date($entry)?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * The coverage with each follow-up thread folded into one item, keyed on its newest part
     * in the hub. Each item is ['entry' => newest part, 'earlier' => the other parts, oldest
     * first]. Newest first.
     *
     * @return Collection<int, array{entry: Entry, earlier: Collection<int, Entry>}>
     */
    public static function items(Entry $hub): Collection
    {
        $coverage = self::coverage($hub);
        $ids = $coverage->map->id()->all();
        $placed = [];

        return $coverage->map(function (Entry $entry) use ($ids, &$placed) {
            if (isset($placed[$entry->id()])) {
                return null;
            }

            $thread = $entry->collectionHandle() === 'videos'
                ? VideoThread::for($entry)->filter(fn (Entry $part) => in_array($part->id(), $ids, true))
                : collect();

            foreach ($thread as $part) {
                $placed[$part->id()] = true;
            }

            return [
                'entry' => $entry,
                'earlier' => $thread->reject(fn (Entry $part) => $part->id() === $entry->id())->values(),
            ];
        })->filter()->values();
    }

    /**
     * Every council motion card in the hub's coverage that names the hub's person, with
     * what they did on it: moved, seconded, and how they voted. Newest first.
     *
     * The cards are already in the articles with the roll call from the minutes, so this is
     * the record of every vote of theirs that was worth writing about, kept current by
     * nothing more than publishing.
     *
     * @return Collection<int, array>
     */
    public static function record(Entry $hub): Collection
    {
        $person = collect($hub->value('person'))->first();

        if (! $person) {
            return collect();
        }

        return self::coverage($hub)->flatMap(function (Entry $entry) use ($person) {
            $body = $entry->collectionHandle() === 'videos' ? $entry->value('article') : $entry->value('content');

            return collect(BardSets::ofType($body, 'motion'))
                ->map(function (array $motion) use ($entry, $person) {
                    $in = fn (string $key) => in_array($person, collect($motion[$key] ?? [])->all(), true);

                    $vote = $in('in_favour') ? 'for' : ($in('opposed') ? 'against' : null);
                    $moved = $in('mover');
                    $seconded = $in('seconder');

                    if (! $vote && ! $moved && ! $seconded) {
                        return null;
                    }

                    $result = in_array($motion['result'] ?? null, ['carried', 'defeated'], true) ? $motion['result'] : null;

                    return [
                        'text' => $motion['text'] ?? '',
                        'kind' => ($motion['kind'] ?? 'motion') === 'amendment' ? 'Amendment' : 'Motion',
                        'result' => $result,
                        'tally' => $result ? count($motion['in_favour'] ?? []).'–'.count($motion['opposed'] ?? []) : null,
                        'moved' => $moved,
                        'seconded' => $seconded,
                        'vote' => $vote,
                        // Voting down a motion they moved is the line people remember.
                        'against_own' => $moved && $vote === 'against',
                        'source' => $motion['source'] ?? null,
                        'source_url' => $motion['source_url'] ?? null,
                        'title' => $entry->value('title'),
                        'url' => $entry->url(),
                        'date' => Writing::date($entry),
                    ];
                })
                ->filter();
        })->values();
    }

    private static function topic(Entry $hub): ?string
    {
        return collect($hub->value('topic'))->first();
    }
}
