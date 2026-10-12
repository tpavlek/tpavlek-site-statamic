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
