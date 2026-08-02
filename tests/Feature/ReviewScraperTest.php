<?php

namespace Tests\Feature;

use App\Fringe\ReviewScraper;
use App\Fringe\UnsupportedReviewSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reading a published review off one of the three sites the generator supports.
 *
 * The HTML fixtures are the real pages as served, kept whole rather than trimmed: the parsing
 * problems here are all about telling review prose apart from subscription furniture, and a
 * tidied-up fixture would test nothing.
 */
class ReviewScraperTest extends TestCase
{
    private const EJ_URL = 'https://edmontonjournal.com/entertainment/theatre/fringe-review-motherhood-the-musical-is-an-amazing-performance-brought-down-by-poor-acoustics';

    private const TN_URL = 'https://12thnight.ca/2025/08/15/the-ticking-explosive-within-bomb-a-fringe-review/';

    private const FR_URL = 'https://reviews.fringetheatre.ca/events/110-wizard/';

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/'.$name);
    }

    /** A 900x1200 JPEG, i.e. something that passes the artwork shape check. */
    private function artwork(): string
    {
        $image = imagecreatetruecolor(900, 1200);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function scraper(): ReviewScraper
    {
        return new ReviewScraper;
    }

    public function test_it_refuses_a_host_it_does_not_know(): void
    {
        $this->expectException(UnsupportedReviewSource::class);

        $this->scraper()->scrape('https://example.com/some-review');
    }

    public function test_it_refuses_a_non_http_scheme(): void
    {
        $this->expectException(UnsupportedReviewSource::class);

        $this->scraper()->scrape('file:///etc/passwd');
    }

    public function test_it_reads_a_rating_author_quote_and_artwork_from_the_edmonton_journal(): void
    {
        Http::fake([
            'edmontonjournal.com/*' => Http::response($this->fixture('edmonton-journal-review.html')),
            '*' => Http::response($this->artwork(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $review = $this->scraper()->scrape(self::EJ_URL);

        $this->assertNull($review->warning);
        $this->assertSame('Motherhood the Musical is an amazing performance brought down by poor acoustics', $review->title);
        $this->assertSame(3.0, $review->stars);
        $this->assertSame('— Justin Bell, Edmonton Journal', $review->attribution);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $review->image);

        $joined = implode(' ', $review->sentences());
        $this->assertStringContainsString('singing clinic', $joined);
        // Subscription and newsletter furniture must not end up offered as a pull quote.
        $this->assertStringNotContainsString('welcome email', $joined);
        $this->assertStringNotContainsString('Subscribe now', $joined);
        $this->assertStringNotContainsString('Check out all of our reviews', $joined);
    }

    public function test_it_reads_12th_night_and_leaves_the_rating_unset(): void
    {
        Http::fake([
            '12thnight.ca/*' => Http::response($this->fixture('twelfth-night-review.html')),
            '*' => Http::response('', 404),
        ]);

        $review = $this->scraper()->scrape(self::TN_URL);

        $this->assertNull($review->warning);
        $this->assertSame('The ticking explosive within! Bomb', $review->title);
        // 12thNight doesn't publish star ratings, so there is nothing to find.
        $this->assertNull($review->stars);
        $this->assertSame('— Liz Nicholls, 12thNight.ca', $review->attribution);
        $this->assertStringContainsString('visceral absurdity', implode(' ', $review->sentences()));
    }

    public function test_it_rejects_a_site_banner_as_artwork(): void
    {
        // 12thNight's og:image is its masthead. A 1200x275 strip stretched over a 1080 square
        // looks broken, so the card should fall through to its flat background instead.
        $banner = imagecreatetruecolor(1200, 275);
        ob_start();
        imagejpeg($banner);
        $bannerBytes = (string) ob_get_clean();

        Http::fake([
            '12thnight.ca/2025/*' => Http::response($this->fixture('twelfth-night-review.html')),
            '*' => Http::response($bannerBytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->assertNull($this->scraper()->scrape(self::TN_URL)->image);
    }

    /**
     * Fringe Reviews posters are routinely 450 square — real cover art, just modest. Behind
     * the card's scrim an upscale of one reads fine, and rejecting it means no artwork at all.
     */
    public function test_it_accepts_a_modest_square_poster(): void
    {
        $this->fakeArtworkOf(450);

        $this->assertNotNull($this->scraper()->scrape(self::TN_URL)->image);
    }

    /** Still small enough to be an icon rather than a poster. */
    public function test_it_rejects_a_thumbnail_as_artwork(): void
    {
        $this->fakeArtworkOf(300);

        $this->assertNull($this->scraper()->scrape(self::TN_URL)->image);
    }

    /**
     * Serves a square JPEG of the given size for the artwork request.
     *
     * Scoped to the article path: a bare 12thnight.ca/* would also swallow the request for
     * their og:image and the "image" would come back as the HTML fixture. It also has to be
     * the only fake in the test — a second Http::fake() merges rather than replaces, so the
     * first '*' stub would keep winning.
     */
    private function fakeArtworkOf(int $size): void
    {
        $image = imagecreatetruecolor($size, $size);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();

        Http::fake([
            '12thnight.ca/2025/*' => Http::response($this->fixture('twelfth-night-review.html')),
            '*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_an_unreachable_page_warns_instead_of_throwing(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $review = $this->scraper()->scrape(self::EJ_URL);

        $this->assertNotNull($review->warning);
        $this->assertSame([], $review->paragraphs);
        $this->assertNull($review->stars);
    }

    public function test_a_page_with_no_review_on_it_warns(): void
    {
        Http::fake(['*' => Http::response('<html><body><p>Nothing to see.</p></body></html>')]);

        $review = $this->scraper()->scrape(self::EJ_URL);

        $this->assertNotNull($review->warning);
        $this->assertTrue($review->isEmpty());
    }

    public function test_it_reads_one_of_troys_own_reviews_without_going_over_the_network(): void
    {
        Http::fake(['*' => Http::response('should not be called', 500)]);

        $review = $this->scraper()->scrape('https://troypavlek.ca/fringe/2025/reviews/100-wizard');

        $this->assertNull($review->warning);
        $this->assertSame('100% Wizard', $review->title);
        $this->assertSame(5.0, $review->stars);
        $this->assertNotEmpty($review->paragraphs);

        Http::assertNothingSent();
    }

    public function test_an_unknown_troy_url_warns_rather_than_404ing(): void
    {
        $review = $this->scraper()->scrape('https://troypavlek.ca/fringe/2025/reviews/not-a-real-show');

        $this->assertNotNull($review->warning);
        $this->assertTrue($review->isEmpty());
    }

    /**
     * The quote picker shows the review as it reads and lets the reader select a run of
     * sentences, so the paragraph boundaries have to survive the scrape.
     */
    public function test_it_keeps_sentences_grouped_by_paragraph(): void
    {
        Http::fake([
            'edmontonjournal.com/*' => Http::response($this->fixture('edmonton-journal-review.html')),
            '*' => Http::response($this->artwork(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $paragraphs = $this->scraper()->scrape(self::EJ_URL)->paragraphs;

        $this->assertNotEmpty($paragraphs);

        foreach ($paragraphs as $paragraph) {
            $this->assertIsArray($paragraph);
            $this->assertNotEmpty($paragraph);
        }

        // More sentences than paragraphs, i.e. the paragraphs really were split up.
        $this->assertGreaterThan(count($paragraphs), count($paragraphs, COUNT_RECURSIVE) - count($paragraphs));
    }

    /**
     * A period inside an abbreviation used to end the sentence, truncating it — and because
     * the fragment sorted first it became the card's default quote.
     */
    public function test_it_does_not_split_a_sentence_on_an_abbreviation(): void
    {
        $split = $this->scraper()->sentencesForParagraphs([
            'Tickets are sold out for the rest of the run. Here it is, to be announced Aug. 22 at noon.',
        ]);

        $this->assertSame([[
            'Tickets are sold out for the rest of the run.',
            'Here it is, to be announced Aug. 22 at noon.',
        ]], $split);
    }

    /**
     * "I was wrong." is twelve characters and the best line in the review it comes from. The
     * old 25-character floor dropped it, which made the whole excerpt unreachable.
     */
    public function test_it_keeps_short_sentences(): void
    {
        $split = $this->scraper()->sentencesForParagraphs([
            'I saw it last year and didn’t think it could get much better. I was wrong.',
        ]);

        $this->assertSame([[
            'I saw it last year and didn’t think it could get much better.',
            'I was wrong.',
        ]], $split);
    }

    /**
     * These articles open with a deck repeating a line from the body verbatim, so the same
     * sentence was offered twice and, being first, was what the card defaulted to.
     */
    public function test_it_drops_a_standfirst_that_repeats_the_body(): void
    {
        $deck = 'Here’s hoping the show will be part of the Fringe holdover series, to be announced in August';
        $body = 'Tickets are sold out for the rest of the run. '.$deck.'.';

        $html = '<html><body><p>'.$deck.'</p><p>'.$body.'</p></body></html>';

        Http::fake(['*' => Http::response($html)]);

        $paragraphs = $this->scraper()->scrape(self::EJ_URL)->paragraphs;

        $this->assertCount(1, $paragraphs);
        $this->assertStringStartsWith('Tickets are sold out', $paragraphs[0][0]);
    }

    /** The consent banner is long enough to look like prose, and was offered as a pull quote. */
    public function test_it_rejects_the_cookie_banner(): void
    {
        $html = '<html><body>'
            .'<p>This website uses cookies to personalize your content (including ads), and allows us to analyze our traffic. Read more about cookies here.</p>'
            .'<p>The two clown rodents have taken their story and made it even funnier, and a good deal more tender besides.</p>'
            .'</body></html>';

        Http::fake(['*' => Http::response($html)]);

        $sentences = implode(' ', $this->scraper()->scrape(self::EJ_URL)->sentences());

        $this->assertStringNotContainsString('cookies', $sentences);
        $this->assertStringContainsString('clown rodents', $sentences);
    }

    /**
     * The official Fringe site lists every review of a show on the show's page, so a link to
     * it is a link to ten reviews and the artist has to pick one.
     */
    public function test_it_reads_every_review_on_a_fringe_reviews_event_page(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response($this->fixture('fringe-reviews-event.html')),
            '*' => Http::response($this->artwork(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $reviews = $this->scraper()->scrapeAll(self::FR_URL);

        $this->assertCount(10, $reviews);
        $this->assertSame('Allison Murray', $reviews[0]->reviewer);
        $this->assertSame('2711', $reviews[0]->reviewId);
        $this->assertSame('July 13, 2026, 4:59 p.m.', $reviews[0]->reviewedAt);
        $this->assertSame('100% Wizard', $reviews[0]->title);
        $this->assertSame('— Allison Murray, Fringe Reviews', $reviews[0]->attribution);
        $this->assertNotEmpty($reviews[0]->paragraphs);

        // Every review carries an id, which is what the chooser round-trips.
        $ids = array_map(fn ($review) => $review->reviewId, $reviews);
        $this->assertCount(10, array_filter($ids));
        $this->assertSame($ids, array_unique($ids));

        // The list doesn't display artwork, so it isn't downloaded to build one.
        foreach ($reviews as $review) {
            $this->assertNull($review->image);
        }
    }

    /**
     * Ratings render as one filled star each with the empty ones left undrawn, so counting
     * them is the rating — and none means unrated, not zero, which is what keeps the card's
     * star switch off rather than showing a made-up nought.
     */
    public function test_it_counts_stars_and_leaves_an_unrated_review_unrated(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response($this->fixture('fringe-reviews-event.html')),
            '*' => Http::response($this->artwork(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $reviews = collect($this->scraper()->scrapeAll(self::FR_URL))->keyBy->reviewId;

        $this->assertSame(5.0, $reviews['2662']->stars);
        $this->assertNull($reviews['2471']->stars, 'Janine Marley left no rating.');
    }

    /**
     * Selecting by id, not by position: a review posted between the two requests shifts
     * every position after it and would hand the artist a different review.
     */
    public function test_it_builds_the_review_the_artist_picked(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response($this->fixture('fringe-reviews-event.html')),
            '*' => Http::response($this->artwork(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $review = $this->scraper()->scrape(self::FR_URL, '2662');

        $this->assertSame('Brian Cheung', $review->reviewer);
        $this->assertNull($review->warning);
        $this->assertStringContainsString('Excellent crowd work', $review->openingLine());
        // The artwork is fetched now that there's one review to put it behind.
        $this->assertStringStartsWith('data:image/jpeg;base64,', $review->image);
    }

    public function test_a_review_that_has_since_gone_falls_back_and_says_so(): void
    {
        Http::fake([
            'reviews.fringetheatre.ca/*' => Http::response($this->fixture('fringe-reviews-event.html')),
            '*' => Http::response($this->artwork(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $review = $this->scraper()->scrape(self::FR_URL, '404404');

        $this->assertSame('Allison Murray', $review->reviewer);
        $this->assertStringContainsString("isn't on the page any more", $review->warning);
    }

    public function test_only_the_fringe_site_lists_many_reviews(): void
    {
        $this->assertTrue($this->scraper()->listsManyReviews(self::FR_URL));
        $this->assertFalse($this->scraper()->listsManyReviews(self::EJ_URL));
        $this->assertFalse($this->scraper()->listsManyReviews(self::TN_URL));
    }

    /** The card starts on the review's opening line, not on whatever sorted first. */
    public function test_the_opening_line_is_the_first_sentence_of_the_first_paragraph(): void
    {
        $review = new \App\Fringe\ScrapedReview(
            sourceName: 'Test',
            paragraphs: [['First sentence.', 'Second sentence.'], ['Third sentence.']],
        );

        $this->assertSame('First sentence.', $review->openingLine());
        $this->assertSame(
            ['First sentence.', 'Second sentence.', 'Third sentence.'],
            $review->sentences(),
        );
    }
}
