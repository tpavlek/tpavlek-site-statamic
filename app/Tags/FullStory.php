<?php

namespace App\Tags;

use App\Support\BardSets;
use App\Support\Writing;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Tags\Tags;

/**
 * The post or Fringe review that tells a video's story at length, for the video page.
 *
 *     {{ full_story }} … {{ /full_story }}
 *
 * One story, one page: when a post or review already covers what a short video says, the
 * video carries no article of its own and points at it through its `full_story` field.
 * That's chosen by hand, not inferred from a post's `related_videos` or a review's
 * `companion_videos`: plenty of those are only extra watching, and shouldn't redirect anyone.
 *
 * `title`, `url`, `image`, `cta` and `is_review`. A post adds `summary`; a review adds
 * `stars`, `year` and `lede` (its opening paragraph), shown on the card so a reader gets the
 * verdict without clicking through. An empty string, not an empty array, when there's no
 * such entry (see Hub::for() for why).
 */
class FullStory extends Tags
{
    public function index(): array|string
    {
        $video = EntryFacade::find($this->params->get('id', $this->context->get('id')));
        $story = ($id = collect($video?->value('full_story'))->first()) ? EntryFacade::find($id) : null;

        if (! $story instanceof Entry || ! $story->published()) {
            return '';
        }

        if ($story->collectionHandle() === 'fringe_reviews') {
            $stars = $story->augmentedValue('stars');

            return [
                'is_review' => true,
                'title' => $story->value('title'),
                'url' => $story->url(),
                'cta' => 'Read the review',
                'image' => $story->augmentedValue('poster')?->value()?->url(),
                'stars' => $stars?->value() ? $stars : null,
                'year' => $story->value('festival'),
                'lede' => BardSets::paragraphs($story->value('content'))[0] ?? null,
            ];
        }

        return [
            'is_review' => false,
            'title' => $story->value('title'),
            'url' => $story->url(),
            'cta' => 'Read the post',
            'image' => $story->augmentedValue('og_image')?->value()?->url(),
            'summary' => $story->augmentedValue('og_description')?->value(),
            'date' => Writing::date($story),
        ];
    }
}
