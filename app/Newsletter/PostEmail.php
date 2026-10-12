<?php

namespace App\Newsletter;

use App\Support\Writing;
use DOMDocument;
use DOMXPath;
use Statamic\Entries\Entry;
use Statamic\View\View;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * A post, or a video with a companion article, as a newsletter email.
 *
 * The website's templates can't be reused: email clients ignore classes, scripts and
 * iframes and mostly ignore floats, so the page's Tailwind layout, YouTube embed and floated
 * figures all fall apart. emails/post renders the same Bard body through email-safe
 * partials, and this class turns that into something a mail client will display:
 *
 *  1. inlines resources/views/emails/email.css onto every element;
 *  2. makes every link and image absolute, since an email has no site to be relative to;
 *  3. strips screen-reader-only text (rating partials carry "4 out of 5 stars" in an
 *     sr-only span, which an email would show in full beside the stars).
 *
 * The result is the email body only; Kit's template wraps it with the unsubscribe footer.
 */
class PostEmail
{
    public function __construct(private Entry $entry) {}

    public static function supports(Entry $entry): bool
    {
        return $entry->collectionHandle() === 'posts' || Writing::hasArticle($entry);
    }

    public function subject(): string
    {
        return (string) $this->entry->value('title');
    }

    /**
     * The grey line inbox lists show after the subject. The share description, which on a
     * video is the override if one is set and the start of the YouTube description if not
     * (see the computed og_description), so there's one place to write a better one.
     */
    public function previewText(): string
    {
        return (string) ($this->entry->augmentedValue('og_description')->value() ?? '');
    }

    public function html(): string
    {
        $isVideo = $this->entry->collectionHandle() === 'videos';

        $html = (new View)
            ->template('emails.post')
            ->cascadeContent($this->entry)
            ->with([
                'is_video' => $isVideo,
                'email_kind' => $isVideo ? 'Video' : 'Post',
                'email_date' => Writing::date($this->entry),
                'video_thumbnail' => $isVideo ? $this->videoThumbnail() : null,
            ])
            ->render();

        $html = (new CssToInlineStyles)->convert($html, file_get_contents(resource_path('views/emails/email.css')));

        return $this->clean($html);
    }

    private function videoThumbnail(): ?string
    {
        $asset = $this->entry->augmentedValue('thumbnail')?->value();
        $asset = is_iterable($asset) ? collect($asset)->first() : $asset;

        if ($asset) {
            return $asset->absoluteUrl();
        }

        $youtube = $this->entry->value('youtube');

        return $youtube ? "https://i.ytimg.com/vi/{$youtube}/hqdefault.jpg" : null;
    }

    /** Widest an image gets, in px: the 600px body less a little breathing room. */
    private const MAX_WIDTH = 560;

    /** Tallest a standalone image gets, and one inside a gallery row. */
    private const MAX_HEIGHT = 520;

    private const MAX_CELL_HEIGHT = 400;

    /**
     * Give an image a real pixel width from its own proportions.
     *
     * "width: 100%" is the trouble: Gmail gives Kit's template a far wider column than the
     * 600px the email is designed for, so a screenshot blew up to fill it and a portrait
     * image (a map of Alberta, a phone screenshot) became several screens tall. Capping the
     * height as well as the width means a tall image comes out narrower rather than longer.
     * max-width stays at 100% so it still shrinks on a phone.
     *
     * Only our own assets have known dimensions; anything else is left as it was.
     */
    private function size(\DOMElement $img): void
    {
        // The template already sized this one on purpose (the video thumbnail).
        if ($img->hasAttribute('width')) {
            return;
        }

        $src = $img->getAttribute('src');
        $path = parse_url($src, PHP_URL_PATH) ?: '';

        if (! str_starts_with($path, '/assets/')) {
            return;
        }

        $asset = \Statamic\Facades\Asset::find('assets::'.urldecode(substr($path, strlen('/assets/'))));
        $width = (int) $asset?->width();
        $height = (int) $asset?->height();

        if (! $width || ! $height) {
            return;
        }

        $cell = $img->parentNode?->nodeName === 'td' ? $img->parentNode : null;

        if ($cell) {
            $columns = max(1, $cell->parentNode->getElementsByTagName('td')->length);
            $maxWidth = intdiv(self::MAX_WIDTH, $columns);
            $maxHeight = self::MAX_CELL_HEIGHT;
        } else {
            $maxWidth = self::MAX_WIDTH;
            $maxHeight = self::MAX_HEIGHT;
        }

        $display = (int) min($maxWidth, $width, round($maxHeight * $width / $height));

        $img->setAttribute('width', (string) $display);
        $img->setAttribute('style', rtrim($img->getAttribute('style'), '; ')."; width: {$display}px; max-width: 100%; height: auto; margin-left: auto; margin-right: auto;");
    }

    /**
     * Absolute URLs, no screen-reader-only text, and just the email's own markup.
     *
     * CssToInlineStyles hands back a whole document; Kit wants a fragment, so only the
     * .email wrapper is returned.
     */
    private function clean(string $html): string
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        foreach (iterator_to_array($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " sr-only ")]')) as $node) {
            $node->parentNode->removeChild($node);
        }

        $base = rtrim(config('app.url'), '/');

        foreach ($xpath->query('//img[@src]') as $img) {
            $this->size($img);
        }

        foreach ($xpath->query('//a[@href] | //img[@src]') as $node) {
            $attribute = $node->nodeName === 'a' ? 'href' : 'src';
            $value = $node->getAttribute($attribute);

            if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
                $node->setAttribute($attribute, $base.$value);
            }
        }

        // Classes did their job when the styles were inlined; leaving them in only invites
        // a client's own stylesheet to match them.
        foreach (iterator_to_array($xpath->query('//*[@class]')) as $node) {
            $node->removeAttribute('class');
        }

        $wrapper = $xpath->query('//body/*')->item(0);

        return $wrapper ? trim($doc->saveHTML($wrapper)) : '';
    }
}
