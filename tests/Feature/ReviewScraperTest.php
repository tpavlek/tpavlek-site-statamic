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
