<?php

namespace App\Http\Controllers;

use App\Feed\Feed;
use App\Fringe\FestivalUrls;
use App\Fringe\Reviews;
use Illuminate\Http\Response;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;

/**
 * RSS feeds.
 *
 *   /feed.xml                 the posts
 *   /fringe/reviews/feed.xml  every review of the current festival, newest first
 *
 * The reviews feed is the interesting one. During the festival it's the only way to follow
 * the reviews as they land without reloading the index, and it's the shape of the site that
 * an aggregator or an assistant can consume without parsing a page — a rating, a verdict and
 * a link, per show, in a format that has been stable for twenty years.
 */
class FeedController extends Controller
{
    /**
     * Posts, newest first.
     *
     * Summaries rather than full text: a post's body is a Bard field with a dozen custom
     * sets — show sections, floated figures, candidate cards, subscribe prompts — and the
     * only thing that knows how to render them is posts/show. Reproducing that here would
     * mean maintaining a second copy of the template that silently rots. A summary and a
     * link is the honest version.
     */
    public function posts(): Response
    {
        $items = EntryFacade::query()
            ->where('collection', 'posts')
            ->where('published', true)
            ->get()
            ->sortByDesc(fn (Entry $entry) => $entry->date())
            ->take(30)
            ->map(fn (Entry $entry) => [
                'title' => $entry->value('title'),
                'url' => $entry->url(),
                'date' => $entry->date(),
                'description' => (string) ($entry->augmentedValue('og_description')->value() ?? ''),
                'content' => null,
            ])
            ->values()
            ->all();

        return $this->respond(Feed::rss(
            'Troy Pavlek',
            'Writing about Edmonton — the Fringe, city hall, and whatever else is going on.',
            '/feed.xml',
            '/posts',
            $items,
        ));
    }

    /**
     * The current festival's reviews, newest first.
     *
     * Full text here, unlike the posts feed: a review's body augments to a plain HTML string,
     * so there's no template logic to duplicate and no reason to make a reader click through
     * to find out whether a show is any good.
     */
    public function fringeReviews(): Response
    {
        $current = FestivalUrls::currentSlug();

        // Published only — an imported lineup entry has no review to carry and its link
        // would 404 in every reader that followed it. See App\Fringe\Reviews.
        $items = Reviews::published()
            ->filter(fn (Entry $entry) => $entry->festival?->slug() === $current)
            // lastModified, not the entry date: entry dates are date-only and a festival puts
            // a dozen reviews on the same day, so sorting by date leaves the running order of
            // any given day to chance. A feed's whole promise is "newest first".
            ->sortByDesc(fn (Entry $entry) => $entry->lastModified() ?? $entry->date())
            ->map(fn (Entry $entry) => [
                'title' => $this->reviewTitle($entry),
                'url' => $entry->url(),
                'date' => $entry->date(),
                'description' => (string) ($entry->augmentedValue('og_description')->value() ?? ''),
                'content' => $this->reviewContent($entry),
            ])
            ->values()
            ->all();

        return $this->respond(Feed::rss(
            "Edmonton Fringe Reviews ({$current}) — Troy Pavlek",
            "Every show I see at the {$current} Edmonton Fringe, rated out of five, as I see them.",
            '/fringe/reviews/feed.xml',
            FestivalUrls::EVERGREEN,
            $items,
        ));
    }

    /**
     * The rating belongs in the title, because a feed reader shows a list of titles and the
     * rating is the most useful thing about a review at a glance.
     *
     * The ★ glyphs come from ReviewRating's label — they're the blueprint's option labels,
     * so the feed can't invent a scale that disagrees with the one the site displays.
     *
     * An inherited rating names the year it was earned, which is the whole reason
     * ReviewRating exists: a returning show borrows last year's stars, and printing them
     * bare against this year's title claims a verdict Troy hasn't reached yet.
     */
    private function reviewTitle(Entry $entry): string
    {
        $title = (string) $entry->value('title');
        $rating = $entry->augmentedValue('rating')->value();

        if (! $rating) {
            return $title;
        }

        $stars = $rating['label'];

        return $rating['inherited'] && $rating['year']
            ? "{$title} — {$stars} (reviewed {$rating['year']})"
            : "{$title} — {$stars}";
    }

    /**
     * A review's body, with a returning show falling back to the review it inherits from —
     * the same rule fringe/review applies, so the feed never carries an empty item for a show
     * whose write-up lives on last year's entry.
     */
    private function reviewContent(Entry $entry): ?string
    {
        $content = (string) ($entry->augmentedValue('content')->value() ?? '');

        if ($content === '') {
            $original = $entry->value('original_review');
            $original = is_array($original) ? ($original[0] ?? null) : $original;
            $original = $original ? EntryFacade::find($original) : null;

            $content = (string) ($original?->augmentedValue('content')->value() ?? '');
        }

        return $content === '' ? null : Feed::absolutise($content);
    }

    private function respond(string $xml): Response
    {
        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }
}
