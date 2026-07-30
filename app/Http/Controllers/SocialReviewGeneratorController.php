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
            ['url' => 'required|url|max:2048'],
            ['url.required' => 'Paste the link to the review first.', 'url.url' => "That doesn't look like a web address."],
        );

        try {
            $review = $this->scraper->scrape($request->input('url'));
        } catch (UnsupportedReviewSource $e) {
            return back()->withInput()->withErrors(['url' => $e->getMessage()]);
        }

        return $this->buildFrom($review);
    }

    private function buildFrom(ScrapedReview $review)
    {
        $lines = $review->lines;

        return view('fringe.social-card.page', array_merge($this->shell(step: 'build'), [
            'stars' => null,
            'showLine' => $review->title,
            'warning' => $review->warning,
            'config' => [
                'quote' => $lines[0] ?? '',
                'reviewLines' => $lines,
                'position' => 100,
                'textSize' => 42,
                'focalX' => 50,
                'focalY' => 50,
                // A rating of null means the source doesn't publish one (12thNight) or we
                // couldn't read it — start with the switch off rather than showing a made-up
                // zero, and let them turn it on if they want to set one.
                'starsEnabled' => $review->stars !== null,
                'starsFixedText' => null,
                'starsValue' => $review->stars ?? 0,
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
            'lede' => "Got a review of your Fringe show? Paste the link and we'll pull out the quote, the rating and the artwork — then you can lay it out and download an image for Instagram.",
            'watchlist' => false,
            'starsEditable' => true,
            'canSave' => false,
            'saveUrl' => null,
            'buildUrl' => route('fringe.social-review-generator.build'),
            'backUrl' => route('fringe.social-review-generator'),
            'backLabel' => 'Start over',
            'posterLabel' => 'Using the image from the review.',
            'resetLabel' => 'Go back to the review’s image',
        ];
    }
}
