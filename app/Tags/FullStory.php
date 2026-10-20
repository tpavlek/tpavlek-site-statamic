<?php

namespace App\Tags;

use App\Support\Writing;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Tags\Tags;

/**
 * The post that tells a video's story at length, for the video page.
 *
 *     {{ full_story }} … {{ /full_story }}
 *
 * One story, one page: when a post already covers what a short video says, the video
 * carries no article of its own and points at the post through its `full_story` field.
 * That's chosen by hand, not inferred from a post's `related_videos`: plenty of posts
 * list videos that are only extra watching, and those shouldn't redirect anyone.
 *
 * `title`, `url`, `summary`, `date` and `image`. An empty string, not an empty array, when
 * there's no such post (see Hub::for() for why).
 */
class FullStory extends Tags
{
    public function index(): array|string
    {
        $video = EntryFacade::find($this->params->get('id', $this->context->get('id')));
        $post = ($id = collect($video?->value('full_story'))->first()) ? EntryFacade::find($id) : null;

        if (! $post instanceof Entry || ! $post->published()) {
            return '';
        }

        return [
            'title' => $post->value('title'),
            'url' => $post->url(),
            'summary' => $post->augmentedValue('og_description')?->value(),
            'date' => Writing::date($post),
            'image' => $post->augmentedValue('og_image')?->value()?->url(),
        ];
    }
}
