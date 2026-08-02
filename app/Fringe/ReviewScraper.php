<?php

namespace App\Fringe;

use App\Http\Controllers\CP\SocialCardController;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;

use Statamic\Facades\Entry as EntryFacade;

/**
 * Pulls the quotable parts of a Fringe review off a published page.
 *
 * Only the three hosts below are accepted. That's the feature scope Troy asked for, and it
 * doubles as the SSRF guard: this endpoint takes a URL from the public and fetches it from
 * the server, so an open fetcher would happily be pointed at internal addresses.
 *
 * To add a source, add its host to SOURCES and write the matching parse method.
 */
class ReviewScraper
{
    private const SOURCES = [
        'edmontonjournal.com' => ['edmontonJournal', 'Edmonton Journal'],
        '12thnight.ca' => ['twelfthNight', '12thNight.ca'],
        'reviews.fringetheatre.ca' => ['fringeReviews', 'Fringe Reviews'],
        'troypavlek.ca' => ['troyPavlek', "Troy's Fringe Reviews"],
    ];

    /**
     * Sources that put many reviews on one page.
     *
     * The official Fringe site has no page per review — they're all listed on the show's
     * event page — so a link to it is a link to every review of that show, and the artist
     * has to say which one they mean before the builder can open.
     */
    private const MULTI_REVIEW = ['fringeReviews'];

    /** Paragraphs shorter than this are captions, bylines and furniture, not review prose. */
    private const MIN_PARAGRAPH = 80;

    /**
     * Consent and legal furniture every one of these sites carries. It reads like prose and is
     * long enough to clear MIN_PARAGRAPH, so without this the cookie banner gets offered as a
     * quotable line.
     */
    private const BOILERPLATE = [
        'uses cookies', 'cookies here', 'terms of use', 'privacy policy', 'all rights reserved',
    ];

    /**
     * Words that end in a period without ending a sentence. "to be announced Aug. 22 at noon"
     * used to split into a truncated fragment, which then became the card's default quote.
     */
    private const ABBREVIATIONS = [
        'Jan', 'Feb', 'Mar', 'Apr', 'Jun', 'Jul', 'Aug', 'Sept', 'Sep', 'Oct', 'Nov', 'Dec',
        'Mon', 'Tues', 'Tue', 'Wed', 'Thurs', 'Thur', 'Thu', 'Fri', 'Sat', 'Sun',
        'Mr', 'Mrs', 'Ms', 'Dr', 'Prof', 'Rev', 'Jr', 'Sr', 'St', 'Ave', 'Blvd', 'Rd',
        'No', 'vs', 'etc', 'Inc', 'Ltd', 'Co', 'approx', 'min', 'Est',
    ];

    public function supportedSources(): array
    {
        return collect(self::SOURCES)->map(fn ($s) => $s[1])->values()->all();
    }

    /** Whether a link to this source is a link to many reviews rather than one. */
    public function listsManyReviews(string $url): bool
    {
        return in_array($this->sourceFor($url)[0], self::MULTI_REVIEW, true);
    }

    /**
     * Every review on the page, for the chooser.
     *
     * The artwork is deliberately not downloaded here — it's one image for the whole show,
     * and the chooser doesn't display it. It's fetched once the artist has picked.
     *
     * @return ScrapedReview[]
     */
    public function scrapeAll(string $url): array
    {
        [$method, $name] = $this->sourceFor($url);

        if (! in_array($method, self::MULTI_REVIEW, true)) {
            $review = $this->scrape($url);

            return $review->isEmpty() ? [] : [$review];
        }

        $html = $this->fetch($url);

        if ($html === null) {
            return [];
        }

        return $this->fringeReviewList($this->parse($html), $name, withImage: false);
    }

    public function scrape(string $url, ?string $reviewId = null): ScrapedReview
    {
        [$method, $name] = $this->sourceFor($url);

        // troypavlek.ca is our own content — read it from the Stache rather than HTTP.
        if ($method === 'troyPavlek') {
            return $this->troyPavlek($url, $name);
        }

        $html = $this->fetch($url);

        if ($html === null) {
            return (new ScrapedReview($name))->withWarning(
                "We couldn't load that page from {$name}. Fill the card in yourself below."
            );
        }

        $doc = $this->parse($html);

        $review = in_array($method, self::MULTI_REVIEW, true)
            ? $this->pickFromList($doc, $name, $reviewId)
            : $this->{$method}($doc, $name);

        return $review->isEmpty()
            ? $review->withWarning("We loaded that {$name} page but couldn't find a review on it. Fill the card in yourself below.")
            : $review;
    }

    /**
     * The chosen review, re-read from the page.
     *
     * Selecting by id rather than position is what makes the round-trip safe: a review
     * posted between the two requests shifts every position after it, and the artist would
     * silently get a different review than the one they clicked.
     */
    private function pickFromList(DOMXPath $xpath, string $name, ?string $reviewId): ScrapedReview
    {
        $reviews = $this->fringeReviewList($xpath, $name, withImage: true);

        if ($reviews === []) {
            return new ScrapedReview($name);
        }

        if ($reviewId === null) {
            return $reviews[0];
        }

        foreach ($reviews as $review) {
            if ($review->reviewId === $reviewId) {
                return $review;
            }
        }

        return $reviews[0]->withWarning(
            "That review isn't on the page any more, so we've opened the most recent one instead."
        );
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws UnsupportedReviewSource
     */
    private function sourceFor(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsupportedReviewSource('That needs to be a web address starting with https://.');
        }

        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        foreach (self::SOURCES as $domain => $source) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $source;
            }
        }

        throw new UnsupportedReviewSource(
            'We can only read reviews from '.implode(', ', $this->supportedSources())
            .". For anywhere else, type the review in yourself."
        );
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'TroysFringeReviews/1.0 (+https://troypavlek.ca/fringe)'])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    private function parse(string $html): DOMXPath
    {
        // Postmedia serves unquoted attributes (property=og:title), so this has to go through
        // a real parser rather than regexes. libxml is noisy about their markup; we don't care.
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    private function meta(DOMXPath $xpath, string $key): ?string
    {
        foreach (['property', 'name'] as $attribute) {
            $nodes = $xpath->query("//meta[@{$attribute}='{$key}']/@content");

            if ($nodes && $nodes->length) {
                $value = trim($nodes->item(0)->nodeValue);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Visible text of the whole document, collapsed — for finding a rating line. */
    private function text(DOMXPath $xpath): string
    {
        foreach ($xpath->query('//script | //style') as $node) {
            $node->parentNode->removeChild($node);
        }

        return trim(preg_replace('/\s+/', ' ', $xpath->document->textContent ?? ''));
    }

    /** @return string[] */
    private function paragraphs(DOMXPath $xpath, string $query, array $reject): array
    {
        $out = [];
        $reject = array_merge($reject, self::BOILERPLATE);

        foreach ($xpath->query($query) as $node) {
            $text = trim(preg_replace('/\s+/', ' ', $node->textContent));

            if (mb_strlen($text) < self::MIN_PARAGRAPH) {
                continue;
            }

            foreach ($reject as $needle) {
                if (stripos($text, $needle) !== false) {
                    continue 2;
                }
            }

            $out[] = $text;
        }

        return $this->withoutEchoes($out);
    }

    /**
     * Drops the standfirst.
     *
     * These articles open with a deck that repeats a sentence from the body verbatim, so the
     * same line was offered twice — and, being first, it was what the card defaulted to.
     * Anything wholly contained in another paragraph is an echo of it, not prose of its own.
     *
     * @param  string[]  $paragraphs
     * @return string[]
     */
    private function withoutEchoes(array $paragraphs): array
    {
        return array_values(array_filter(
            $paragraphs,
            function (string $paragraph, int $i) use ($paragraphs) {
                foreach ($paragraphs as $j => $other) {
                    if ($i === $j) {
                        continue;
                    }

                    if ($other === $paragraph) {
                        return $j > $i; // Exact repeat: keep whichever came first.
                    }

                    if (mb_strlen($other) > mb_strlen($paragraph) && str_contains($other, $paragraph)) {
                        return false;
                    }
                }

                return true;
            },
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Split prose into sentences, keeping them grouped by paragraph.
     *
     * Nothing is dropped for being short. "I was wrong." is twelve characters and the best
     * line in the review it comes from; the picker lets it be selected together with the
     * sentence before it, which is the only way it works as a pull quote.
     *
     * @param  string[]  $paragraphs
     * @return string[][]
     */
    public function sentencesForParagraphs(array $paragraphs): array
    {
        return collect($paragraphs)
            ->map(fn ($paragraph) => $this->splitSentences($paragraph))
            ->filter()
            ->values()
            ->all();
    }

    /** @return string[] */
    private function splitSentences(string $paragraph): array
    {
        $marker = "\x1a";

        // Hide the periods that don't end sentences, split, then put them back. A lookbehind
        // can't do this — PCRE won't take a variable-length one — and the alternative of
        // listing abbreviations inside the split pattern misses the general case.
        $protected = preg_replace_callback(
            '/\b('.implode('|', self::ABBREVIATIONS).')\.|\b[A-Z]\.|\b[a-z]\.[a-z]\./u',
            fn ($m) => str_replace('.', $marker, $m[0]),
            $paragraph,
        );

        // Break on whitespace after terminal punctuation, but only where a new sentence
        // plausibly starts — a capital, optionally behind an opening quote. \K keeps the
        // punctuation with the sentence it closes.
        $parts = preg_split('/[.!?]+["\'”’\)]*\K\s+(?=["\'“‘\(]*\p{Lu})/u', $protected) ?: [];

        return collect($parts)
            ->map(fn ($s) => trim(str_replace($marker, '.', $s)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Downloads the article artwork and returns it as a data: URI.
     *
     * It has to come back inline rather than as a URL: the card is rasterized in the browser
     * with html-to-image, and a cross-origin image with no CORS headers — which is every
     * newspaper CDN — taints the canvas and the render fails.
     *
     * Banners and logos are rejected on shape and size, so a site whose og:image is its
     * masthead (12thNight) falls through to the card's flat background instead of a
     * 1200x275 strip stretched over the whole thing.
     */
    private function image(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        try {
            $response = Http::timeout(12)->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        if (strlen($body) > 6_000_000) {
            return null;
        }

        $info = @getimagesizefromstring($body);

        if (! $info) {
            return null;
        }

        [$width, $height] = $info;
        $mime = $info['mime'] ?? '';

        if (! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return null;
        }

        // Too small to fill a 1080px card, or too strip-shaped to be artwork.
        //
        // 400 rather than 500 because Fringe Reviews posters are routinely 450 square —
        // real cover art, just modest. Upscaled behind the scrim it reads fine, and the
        // alternative is the flat gradient, i.e. no artwork at all. Banners are caught by
        // the ratio check below rather than by this one.
        if (max($width, $height) < 400) {
            return null;
        }

        $ratio = $width / max(1, $height);

        if ($ratio > 2.2 || $ratio < 0.45) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($body);
    }

    /**
     * Postmedia. The rating sits in the body as "3 Stars out of 5" and the critic in a
     * parsely-author meta; the prose is not paywalled but is threaded through subscription
     * and newsletter furniture, hence the reject list.
     */
    private function edmontonJournal(DOMXPath $xpath, string $name): ScrapedReview
    {
        $title = $this->meta($xpath, 'og:title');
        $title = $title ? trim(preg_replace('/^fringe review:\s*/i', '', $title)) : null;

        $stars = null;

        if (preg_match('/(\d(?:\.\d)?)\s*stars?\s*out\s*of\s*5/i', $this->text($xpath), $m)) {
            $stars = (float) $m[1];
        }

        $author = $this->meta($xpath, 'parsely-author');

        $paragraphs = $this->paragraphs($xpath, '//p', [
            'subscribe', 'sign in', 'sign-in', 'your account', 'newsletter', 'welcome email',
            'Postmedia', 'advertisement', 'we apologize', 'share this', 'commission',
            'conversation', 'check out all of our reviews', 'manage print',
        ]);

        return new ScrapedReview(
            sourceName: $name,
            title: $title,
            paragraphs: $this->sentencesForParagraphs($paragraphs),
            stars: $stars,
            attribution: $author ? "— {$author}, {$name}" : "— {$name}",
            image: $this->image($this->meta($xpath, 'og:image')),
        );
    }

    /**
     * 12thNight publishes no star ratings, so nothing sets one here — the generator's star
     * switch is what makes that fine. Its og:image is the site masthead, which image()
     * rejects on shape.
     */
    private function twelfthNight(DOMXPath $xpath, string $name): ScrapedReview
    {
        $title = $this->meta($xpath, 'og:title');
        $title = $title ? trim(preg_replace('/[,:]?\s*a fringe review\s*$/i', '', $title)) : null;

        $author = null;

        if (preg_match('/By ([A-Z][a-z]+(?: [A-Z][a-z\'’]+)+)/u', (string) $this->meta($xpath, 'og:description'), $m)) {
            $author = trim($m[1]);
        }

        // Photo credits are paragraphs too, and a long one clears MIN_PARAGRAPH: "Jeff and
        // Ryan Gladstone in Riot! Monster Theatre at Edmonton Fringe 2025. Photo supplied" is
        // 88 characters, so it was being offered as review prose — and being first, it was
        // the line the card opened on. Excluding them by class beats guessing at their wording.
        $paragraphs = $this->paragraphs(
            $xpath,
            "//div[contains(@class,'entry-content')]//p[not(contains(@class,'wp-caption-text'))]",
            ['to help support', '12thnight.ca theatre coverage', 'click here', 'poster by'],
        );

        return new ScrapedReview(
            sourceName: $name,
            title: $title,
            paragraphs: $this->sentencesForParagraphs($paragraphs),
            stars: null,
            attribution: $author ? "— {$author}, {$name}" : "— {$name}",
            // The production photo is in the article, not in a meta tag — 12thNight's
            // og:image is always the site masthead, which image() rejects on shape. So the
            // body is the first place to look and og:image is only the fallback.
            image: $this->contentImage($xpath, "//div[contains(@class,'entry-content')]//img")
                ?? $this->image($this->meta($xpath, 'og:image')),
        );
    }

    /**
     * The first image in the article body that's usable as a card background.
     *
     * Everything still goes through image(), so the shape and size rules do the discriminating
     * — which is what separates the show photo from the Patreon badge sitting under it without
     * having to recognise either. Capped at a handful of candidates so a page full of images
     * can't turn one crawl into dozens of downloads.
     */
    private function contentImage(DOMXPath $xpath, string $query): ?string
    {
        $tried = 0;

        foreach ($xpath->query($query) as $img) {
            if ($tried >= 4) {
                break;
            }

            $url = $this->largestSource($img);

            if ($url === null) {
                continue;
            }

            $tried++;

            if ($image = $this->image($url)) {
                return $image;
            }
        }

        return null;
    }

    /**
     * The biggest rendition an <img> offers: the widest candidate in its srcset, else its src.
     *
     * WordPress serves a 640px-wide src with a srcset going up to 1280 — taking the src would
     * mean a soft background on a 1080px card when a sharp one was on offer.
     */
    private function largestSource(\DOMElement $img): ?string
    {
        $best = null;
        $bestWidth = -1;

        foreach (explode(',', $img->getAttribute('srcset')) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate));

            if (($parts[0] ?? '') === '') {
                continue;
            }

            $width = (int) rtrim($parts[1] ?? '0', 'w');

            if ($width > $bestWidth) {
                $best = $parts[0];
                $bestWidth = $width;
            }
        }

        $url = $best ?? $img->getAttribute('src');

        // Only absolute http(s): this walks markup from a third-party page, and a relative or
        // exotic-scheme src is either useless or something we shouldn't be fetching.
        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    /**
     * The official Fringe review site. Every review of a show lives on the show's event page
     * — there is no page per review — so this returns all of them and the artist picks.
     *
     * Ratings are rendered as one filled star SVG per point, with the empty ones simply not
     * drawn, so counting them is the rating. A review with none is unrated rather than
     * zero-rated: the card starts with the star switch off, the same as 12thNight.
     *
     * @return ScrapedReview[]
     */
    private function fringeReviewList(DOMXPath $xpath, string $name, bool $withImage): array
    {
        $title = $this->meta($xpath, 'og:title');
        $title = $title ? trim(preg_replace('/\s*[-–]\s*Fringe Reviews\s*$/i', '', $title)) : null;

        $image = $withImage ? $this->image($this->meta($xpath, 'og:image')) : null;

        $reviews = [];

        foreach ($xpath->query('//div[@id="reviews"]//div[contains(@class,"rounded-2xl")]') as $block) {
            $reviewer = null;

            foreach ($xpath->query('.//a[starts-with(@href,"/reviewers/")]', $block) as $link) {
                $reviewer = trim($link->textContent);
                break;
            }

            // The report link is the only stable id the markup exposes, and it sits in the
            // block's sibling rather than inside it.
            $reviewId = null;

            foreach ($xpath->query('following-sibling::div[1]//a[contains(@href,"/reports/create/")]', $block) as $link) {
                if (preg_match('/review=(\d+)/', $link->getAttribute('href'), $matches)) {
                    $reviewId = $matches[1];
                    break;
                }
            }

            $stars = $xpath->query('.//svg[contains(@class,"fill-yellow-500")]', $block)->length;

            // Two muted lines under the name: which festival, then when.
            $meta = [];

            foreach ($xpath->query('.//div[contains(@class,"opacity-60")]', $block) as $node) {
                $meta[] = trim(preg_replace('/\s+/', ' ', $node->textContent));
            }

            $paragraphs = [];

            foreach ($xpath->query('.//div[contains(@class,"prose")]//p', $block) as $node) {
                $text = trim(preg_replace('/\s+/', ' ', $node->textContent));

                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }

            if ($paragraphs === []) {
                continue;
            }

            $reviews[] = new ScrapedReview(
                sourceName: $name,
                title: $title,
                paragraphs: $this->sentencesForParagraphs($paragraphs),
                stars: $stars > 0 ? (float) $stars : null,
                attribution: $reviewer ? "— {$reviewer}, {$name}" : "— {$name}",
                image: $image,
                reviewId: $reviewId,
                reviewer: $reviewer,
                reviewedAt: $meta[1] ?? null,
            );
        }

        return $reviews;
    }

    /**
     * Our own reviews. Read from the Stache rather than fetched over HTTP, which also means
     * the quote lines and star handling are exactly the ones the entry share card uses.
     */
    private function troyPavlek(string $url, string $name): ScrapedReview
    {
        $path = '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
        $entry = EntryFacade::findByUri($path);

        if (! $entry || $entry->collectionHandle() !== 'fringe_reviews') {
            return (new ScrapedReview($name))->withWarning(
                "That doesn't look like one of Troy's Fringe reviews. Fill the card in yourself below."
            );
        }

        $shared = app(SocialCardController::class);

        return new ScrapedReview(
            sourceName: $name,
            title: $entry->value('title'),
            paragraphs: $shared->quotableParagraphs($entry),
            stars: $shared->starsValue($entry),
            attribution: "— {$name}",
            image: $entry->poster?->url(),
        );
    }
}
