<?php

namespace App\Tags;

use App\Support\BardSets;
use App\Support\Hubs;
use App\Support\ReadingTime;
use App\Support\Writing;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Tags\Tags;

/**
 * The content hub at /posts (and /videos, which opens it on Watch): everything, newest first.
 *
 *     {{ content_hub }} {{ hubs }} … {{ months }} {{ cards }} … {{ shorts }} … {{ /months }} {{ /content_hub }}
 *
 * Grouped by month. The pieces with words (posts, and videos with an article) are cards
 * with their share image; each month's videos without an article are a row of 9:16 covers
 * (`shorts`), the shape they look best in. A video whose `full_story` is a post or review
 * is left out, and the post carries a video marker instead: one story, one page.
 *
 * `hubs` leads the page, and each card lists the hubs it belongs to. Filtering (All / Read /
 * Watch, and topic) is client-side, in the contentHub Alpine component.
 */
class ContentHub extends Tags
{
    /** @var array<string, array<int, array{title: string, url: string}>> */
    private array $hubsFor = [];

    public function index(): array
    {
        $videos = EntryFacade::query()->where('collection', 'videos')->where('published', true)->get();
        $posts = EntryFacade::query()->where('collection', 'posts')->where('published', true)->get();

        // Posts that a video names as its full story get the video's marker.
        $videoFor = $videos
            ->filter(fn (Entry $v) => BardSets::first($v->value('full_story')))
            ->mapWithKeys(fn (Entry $v) => [BardSets::first($v->value('full_story')) => $v]);

        // Hubs, for the row up top and for the label on each piece that belongs to one.
        $hubs = Hubs::all()->map(fn (Entry $hub) => [
            'entry' => $hub,
            'coverage' => Hubs::coverage($hub)->map->id()->all(),
        ]);
        $this->hubsFor = [];
        foreach ($hubs as $hub) {
            foreach ($hub['coverage'] as $id) {
                $this->hubsFor[$id][] = ['title' => $hub['entry']->value('title'), 'url' => $hub['entry']->url()];
            }
        }

        $items = collect();

        foreach ($posts as $post) {
            $items->push($this->card($post, 'read', $videoFor->has($post->id())));
        }

        foreach ($videos as $video) {
            if (BardSets::first($video->value('full_story'))) {
                continue;
            }

            $items->push(Writing::hasArticle($video)
                ? $this->card($video, 'both', true)
                : $this->short($video));
        }

        $months = $items
            ->filter(fn (array $item) => $item['date'])
            ->sortByDesc(fn (array $item) => $item['date']->getTimestamp())
            ->groupBy(fn (array $item) => $item['date']->format('Y-m'))
            ->map(fn ($group) => [
                'label' => $group->first()['date']->format('F Y'),
                'cards' => $group->where('kind', '!=', 'watch')->values()->all(),
                'shorts' => $group->where('kind', 'watch')->values()->all(),
            ])
            ->values();

        // Only the newest piece overall runs wide.
        $months = $months->map(function (array $month, int $i) {
            foreach ($month['cards'] as $j => $card) {
                $month['cards'][$j]['featured'] = $i === 0 && $j === 0;
            }

            return $month;
        });

        return [
            'hubs' => $hubs->map(fn (array $hub) => [
                'title' => $hub['entry']->value('title'),
                'url' => $hub['entry']->url(),
                'kicker' => $hub['entry']->value('kicker'),
                'photo' => $this->hubPhoto($hub['entry']),
                'total' => count($hub['coverage']),
            ])->sortByDesc('total')->values()->all(),
            'months' => $months->all(),
            'counts' => [
                'all' => $items->count(),
                'read' => $items->whereIn('kind', ['read', 'both'])->count(),
                'watch' => $items->filter(fn ($i) => $i['kind'] !== 'read' || $i['has_video'])->count(),
            ],
        ];
    }

    private function card(Entry $entry, string $kind, bool $hasVideo): array
    {
        $body = $entry->collectionHandle() === 'videos' ? 'article' : 'content';

        return [
            'kind' => $kind,
            'has_video' => $hasVideo,
            'title' => $entry->value('title'),
            'url' => $entry->url(),
            'date' => Writing::date($entry),
            'summary' => $entry->augmentedValue('og_description')?->value(),
            'image' => $this->asset($entry, 'og_image') ?? $this->asset($entry, 'thumbnail'),
            'minutes' => ReadingTime::minutes($entry->value($body)),
            'topics' => collect($entry->value('topics'))->implode(' '),
            'hubs' => $this->hubsFor[$entry->id()] ?? [],
        ];
    }

    private function short(Entry $entry): array
    {
        return [
            'kind' => 'watch',
            'has_video' => true,
            'title' => $entry->value('title'),
            'url' => $entry->url(),
            'date' => Writing::date($entry),
            'cover' => $this->asset($entry, 'thumbnail')
                ?? (($yt = $entry->value('youtube')) ? "https://i.ytimg.com/vi/{$yt}/hqdefault.jpg" : null),
            'topics' => collect($entry->value('topics'))->implode(' '),
        ];
    }

    private function hubPhoto(Entry $hub): ?string
    {
        if ($photo = $this->asset($hub, 'photo')) {
            return $photo;
        }

        $person = ($id = BardSets::first($hub->value('person'))) ? EntryFacade::find($id) : null;

        return $person ? $this->asset($person, 'photo') : null;
    }

    private function asset(Entry $entry, string $field): ?string
    {
        $asset = $entry->augmentedValue($field)?->value();
        $asset = is_iterable($asset) ? collect($asset)->first() : $asset;

        return $asset?->url();
    }
}
