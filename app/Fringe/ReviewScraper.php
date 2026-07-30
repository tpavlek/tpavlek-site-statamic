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
        'troypavlek.ca' => ['troyPavlek', "Troy's Fringe Reviews"],
    ];

    /** Paragraphs shorter than this are captions, bylines and furniture, not review prose. */
    private const MIN_PARAGRAPH = 80;

    public function supportedSources(): array
    {
        return collect(self::SOURCES)->map(fn ($s) => $s[1])->values()->all();
    }

    public function scrape(string $url): ScrapedReview
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

        $review = $this->{$method}($doc, $name);

        return $review->isEmpty()
            ? $review->withWarning("We loaded that {$name} page but couldn't find a review on it. Fill the card in yourself below.")
            : $review;
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

        return $out;
    }

    /** Split prose into sentences, the same unit the quote picker offers. */
    private function sentences(array $paragraphs): array
    {
        return collect($paragraphs)
            ->flatMap(fn ($p) => preg_split('/(?<=[.!?])\s+/', $p) ?: [])
            ->map(fn ($s) => trim($s))
            ->filter(fn ($s) => mb_strlen($s) > 25 && mb_strlen($s) <= 280)
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
        if (max($width, $height) < 500) {
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
            lines: $this->sentences($paragraphs),
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

        $paragraphs = $this->paragraphs(
            $xpath,
            "//div[contains(@class,'entry-content')]//p",
            ['to help support', '12thnight.ca theatre coverage', 'click here', 'poster by'],
        );

        return new ScrapedReview(
            sourceName: $name,
            title: $title,
            lines: $this->sentences($paragraphs),
            stars: null,
            attribution: $author ? "— {$author}, {$name}" : "— {$name}",
            image: $this->image($this->meta($xpath, 'og:image')),
        );
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
            lines: $shared->quotableLines($entry),
            stars: $shared->starsValue($entry),
            attribution: "— {$name}",
            image: $entry->poster?->url(),
        );
    }
}
