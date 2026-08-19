<?php

namespace App\Http\Controllers;

use App\Fringe\ReviewScraper;
use App\Fringe\ScrapedReview;
use App\Fringe\UnsupportedReviewSource;
use Illuminate\Http\Request;
use Statamic\Support\Str;

/**
 * The public share-card generator: any artist, any review, any publication.
 *
 * It renders the same view, card and Alpine component as the share card for one of Troy's own
 * reviews — see fringe/social-card/page. The only differences are that the rating is editable
 * (a third-party review may not have one, or may have one we couldn't parse) and that nothing
 * is saved anywhere: the output is a PNG the user downloads.
 */
class SocialReviewGeneratorController extends Controller
{
    public function __construct(private readonly ReviewScraper $scraper) {}

    public function index()
    {
        return view('fringe.social-card.page', array_merge($this->shell(step: 'choose'), [
            'stars' => null,
            'warning' => null,
            'config' => null,
        ]));
    }

    public function build(Request $request)
    {
        if ($request->boolean('manual')) {
            return $this->buildFrom(new ScrapedReview(sourceName: ''));
        }

        $request->validate(
            ['url' => 'required|url|max:2048', 'review' => 'nullable|string|max:64'],
            ['url.required' => 'Paste the link to the review first.', 'url.url' => "That doesn't look like a web address."],
        );

        $url = $request->input('url');
        $chosen = $request->input('review');

        try {
            // The official Fringe site has no page per review — a link to a show is a link to
            // every review of it — so the artist says which one they mean before the builder
            // opens. One review needs no choosing, and none falls through to the warning.
            if ($chosen === null && $this->scraper->listsManyReviews($url)) {
                $reviews = $this->scraper->scrapeAll($url);

                if (count($reviews) > 1) {
                    return $this->chooseFrom($url, $reviews);
                }
            }

            $review = $this->scraper->scrape($url, $chosen);
        } catch (UnsupportedReviewSource $e) {
            return back()->withInput()->withErrors(['url' => $e->getMessage()]);
        }

        return $this->buildFrom($review);
    }

    /**
     * @param  ScrapedReview[]  $reviews
     */
    private function chooseFrom(string $url, array $reviews)
    {
        $show = $reviews[0]->title;

        return view('fringe.social-card.page', array_merge($this->shell(step: 'select'), [
            'stars' => null,
            'showLine' => $show,
            'heading' => 'Which review?',
            'lede' => 'There are '.count($reviews).' reviews of '.($show ? '“'.$show.'”' : 'this show')
                .' on Fringe Reviews. Pick the one you want on your card — you can change the wording afterwards.',
            'warning' => null,
            'config' => null,
            'reviews' => $reviews,
            'sourceUrl' => $url,
        ]));
    }

    private function buildFrom(ScrapedReview $review)
    {
        return view('fringe.social-card.page', array_merge($this->shell(step: 'build'), [
            'stars' => null,
            'showLine' => $review->title,
            'warning' => $review->warning,
            'config' => [
                'quote' => $review->openingLine() ?? '',
                'reviewParagraphs' => $review->paragraphs,
                'position' => 100,
                'textSize' => 42,
                'focalX' => 50,
                'focalY' => 50,
                'zoom' => 100,
                // A rating of null means the source doesn't publish one (12thNight) or we
                // couldn't read it — start with the switch off rather than showing a made-up
                // zero, and let them turn it on if they want to set one.
                'starsEnabled' => $review->stars !== null,
                'starsColour' => 'white',
                'starsSize' => 72,
                'starsFixedText' => null,
                'starsValue' => $review->stars ?? 0,
                // Off by default, but pre-filled from the scraped title so turning it on
                // does something immediately.
                'titleEnabled' => false,
                'titleText' => $review->title ?? '',
                'titleSize' => 44,
                'attributionEnabled' => $review->attribution !== null,
                'attributionText' => $review->attribution ?? '',
                'attributionSize' => 34,
                'posterUrl' => $review->image,
                'savedShareUrl' => null,
                'downloadName' => $review->title ? Str::slug($review->title) : 'fringe-review',
                'ogUrl' => null,
            ],
        ]));
    }

    private function shell(string $step): array
    {
        return [
            'mode' => 'generator',
            'step' => $step,
            'pageTitle' => 'Fringe review social image generator — Troy Pavlek',
            'eyebrow' => "Troy's Fringe Reviews",
            'heading' => 'Turn a review into a shareable image',
            'showLine' => null,
            'lede' => "Paste in a link to a prominent review site, or manually copy over review details. This tool can generate Instagram-ready images for stories and feeds.",
            'watchlist' => false,
            'starsEditable' => true,
            'canSave' => false,
            'existingOgUrl' => null,
            'saveUrl' => null,
            'buildUrl' => route('fringe.social-review-generator.build'),
            'backUrl' => route('fringe.social-review-generator'),
            'backLabel' => 'Start over',
            'posterLabel' => 'Using the image from the review.',
            'resetLabel' => 'Go back to the review’s image',
        ];
    }
}
