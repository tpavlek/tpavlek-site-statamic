<?php

namespace App\Http\Controllers\CP;

use App\Http\Controllers\Controller;
use App\Fringe\ReviewScraper;
use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\User as UserFacade;

class SocialCardController extends Controller
{
    public function show(string $entryId)
    {
        $entry = $this->findReview($entryId);

        return $this->builder($entry, backUrl: $entry->editUrl(), backLabel: 'Back to entry');
    }

    public function publicShow(string $festival, string $slug)
    {
        $entry = EntryFacade::findByUri("/fringe/{$festival}/reviews/{$slug}");

        abort_unless($entry && $entry->collectionHandle() === 'fringe_reviews', 404);

        return $this->builder($entry, backUrl: $entry->url(), backLabel: 'Back to review');
    }

    /**
     * The card builder, for the CP and for the public per-review URL both.
     *
     * The response carries `X-Robots-Tag: noindex` as well as the meta tag in the template.
     * Both, because Search Console's URL inspection reported this page as "Submitted and
     * indexed" with `indexingState: INDEXING_ALLOWED` — Google last crawled it the day the
     * meta tag shipped and evidently didn't have it yet, and a header is the directive that
     * can't be missed by a parser or a truncated response. OgCardController does the same.
     *
     * Deliberately noindex rather than canonical-to-the-review. This is a tool, not a copy of
     * the review, so a canonical would be a claim that isn't true — and Google's own guidance
     * is not to combine noindex with a canonical, because the conflicting signals can carry
     * the noindex across to the target. The target here is the review page itself.
     */
    private function builder(Entry $entry, string $backUrl, string $backLabel)
    {
        $options = collect($entry->value('share_card_options') ?? []);
        $paragraphs = $this->quotableParagraphs($entry);
        $stars = $this->starsLabel($entry);
        $canSave = UserFacade::current() !== null;

        return response()->view('fringe.social-card.page', [
            'mode' => 'entry',
            'step' => 'build',
            'pageTitle' => 'Share this review — '.$entry->value('title'),
            'eyebrow' => "Troy's Fringe Reviews",
            'heading' => 'Share this review',
            'showLine' => $entry->value('title'),
            'stars' => $stars,
            'lede' => $canSave
                ? 'Tweak the card, save the settings to the entry, and download the PNG.'
                : 'Turn this review into an image for Instagram. Pick your favourite line, adjust the layout, and download.',
            'watchlist' => ! $stars && $entry->recommendation->value() === 'watchlist',
            'starsEditable' => false,
            'warning' => null,
            'canSave' => $canSave,
            // So the OpenGraph tab can say whether this show already has one, and link to it.
            'existingOgUrl' => $canSave ? $entry->og_image?->url() : null,
            'saveUrl' => cp_route('fringe-social-card.save', $entry->id()),
            'buildUrl' => null,
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
            'config' => [
                'quote' => $entry->value('share_quote') ?: ($paragraphs[0][0] ?? ''),
                'reviewParagraphs' => $paragraphs,
                'position' => (int) $options->get('position', 100),
                'textSize' => (int) $options->get('text_size', 42),
                'focalX' => (int) $options->get('focal_x', 50),
                'focalY' => (int) $options->get('focal_y', 50),
                'zoom' => (int) $options->get('zoom', 100),
                'starsEnabled' => (bool) $options->get('stars_enabled', true),
                'starsColour' => (string) $options->get('stars_colour', 'white'),
                'starsSize' => (int) $options->get('stars_size', 72),
                // Fixed: the rating belongs to the review, so the switch only hides it.
                'starsFixedText' => (string) $stars,
                'starsValue' => 0,
                'titleEnabled' => (bool) $options->get('title_enabled', false),
                'titleText' => (string) $options->get('title_text', $entry->value('title')),
                'titleSize' => (int) $options->get('title_size', 44),
                'attributionEnabled' => (bool) $options->get('attribution_enabled', true),
                'attributionText' => (string) $options->get('attribution_text', "\u{2014} Troy's Fringe Reviews"),
                'attributionSize' => (int) $options->get('attribution_size', 34),
                'posterUrl' => $entry->poster?->url(),
                'savedShareUrl' => $entry->share_image?->url(),
                'downloadName' => $entry->slug(),
                'ogUrl' => $canSave ? cp_route('fringe-social-card.og', $entry->id()) : null,
            ],
        ])->header('X-Robots-Tag', 'noindex');
    }

    public function save(string $entryId, Request $request)
    {
        $entry = $this->findReview($entryId);

        $request->validate([
            // Nullable so a stars-only card can be saved: with no quote the card drops the
            // quotation marks entirely rather than showing an empty pair.
            'quote' => 'nullable|string|max:280',
            'position' => 'required|integer|between:0,100',
            'focal_x' => 'required|integer|between:0,100',
            'focal_y' => 'required|integer|between:0,100',
            'zoom' => 'required|integer|between:100,300',
            'text_size' => 'required|integer|between:20,150',
            'stars_enabled' => 'required|boolean',
            'stars_colour' => 'required|in:white,black,gold,teal',
            'stars_size' => 'required|integer|between:24,150',
            'title_enabled' => 'required|boolean',
            'title_text' => 'nullable|string|max:120',
            'title_size' => 'required|integer|between:20,100',
            'attribution_enabled' => 'required|boolean',
            'attribution_text' => 'nullable|string|max:120',
            'attribution_size' => 'required|integer|between:16,80',
            'image' => 'nullable|image|max:10240',
            'clear_image' => 'nullable|boolean',
        ], $this->validationMessages());

        if ($request->file('image')) {
            $entry->set('share_image', $this->uploadBackground($entry, $request));
        } elseif ($request->boolean('clear_image')) {
            $entry->remove('share_image');
        }

        $entry
            ->set('share_quote', $request->input('quote'))
            ->set('share_card_options', [
                'position' => (int) $request->input('position'),
                'focal_x' => (int) $request->input('focal_x'),
                'focal_y' => (int) $request->input('focal_y'),
                'zoom' => (int) $request->input('zoom'),
                'text_size' => (int) $request->input('text_size'),
                'stars_enabled' => $request->boolean('stars_enabled'),
                'stars_colour' => (string) $request->input('stars_colour'),
                'stars_size' => (int) $request->input('stars_size'),
                'title_enabled' => $request->boolean('title_enabled'),
                'title_text' => (string) $request->input('title_text'),
                'title_size' => (int) $request->input('title_size'),
                'attribution_enabled' => $request->boolean('attribution_enabled'),
                'attribution_text' => (string) $request->input('attribution_text'),
                'attribution_size' => (int) $request->input('attribution_size'),
            ])
            ->save();

        return redirect(cp_route('fringe-social-card.show', $entryId))
            ->with('success', 'Share card saved.');
    }

    /**
     * Takes the rendered card and hangs it on the entry as its OpenGraph image, so a
     * review's share preview can be an actual excerpt of the review rather than the
     * generic Fringe fallback.
     *
     * CP-only, like the rest of this controller: the route sits behind Statamic's CP
     * middleware, so the public share-card page can't reach it.
     */
    public function setOgImage(string $entryId, Request $request)
    {
        $entry = $this->findReview($entryId);

        $request->validate([
            'image' => 'required|image|mimes:png|max:10240',
        ], $this->validationMessages());

        $file = $request->file('image');
        $path = 'fringe/og/'.$entry->slug().'.png';

        $container = AssetContainer::findByHandle('assets');

        // Replace any previous card for this review rather than accumulating copies
        $container->asset($path)?->delete();
        $container->makeAsset($path)->upload($file);

        $entry->set('og_image', $path)->save();

        return response()->json([
            'message' => 'Set as the OpenGraph image.',
            'path' => $path,
        ]);
    }

    /**
     * Statamic's lang file drops :attribute from the validation messages, which reads fine
     * in the CP where the label sits beside the field but leaves this standalone form
     * saying "This field is required." with no clue which field. Name them.
     */
    private function validationMessages(): array
    {
        $labels = [
            'quote' => 'Quote',
            'position' => 'Text position',
            'focal_x' => 'Horizontal focus',
            'focal_y' => 'Vertical focus',
            'zoom' => 'Zoom',
            'text_size' => 'Text size',
            'stars_enabled' => 'Star rating toggle',
            'stars_colour' => 'Star colour',
            'stars_size' => 'Star size',
            'title_enabled' => 'Show title toggle',
            'title_text' => 'Show title',
            'title_size' => 'Show title size',
            'attribution_enabled' => 'Attribution toggle',
            'attribution_text' => 'Attribution text',
            'attribution_size' => 'Attribution text size',
            'image' => 'Background image',
            'clear_image' => 'Background image',
        ];

        $templates = [
            'required' => ':label is missing.',
            'string' => ':label must be text.',
            'integer' => ':label must be a whole number.',
            'boolean' => ':label must be on or off.',
            'between' => ':label is out of range.',
            'max' => ':label is too large.',
            'image' => ':label must be an image file.',
            'mimes' => ':label must be a PNG.',
        ];

        $messages = [];

        foreach ($labels as $field => $label) {
            foreach ($templates as $rule => $template) {
                $messages["{$field}.{$rule}"] = str_replace(':label', $label, $template);
            }
        }

        return $messages;
    }

    private function findReview(string $entryId): Entry
    {
        $entry = EntryFacade::find($entryId);

        abort_unless($entry && $entry->collectionHandle() === 'fringe_reviews', 404);

        return $entry;
    }

    /**
     * The star label for the card, inheriting from the original review for restagings.
     * Empty stars are dropped (★★★★☆ → ★★★★), except for a zero-star rating.
     */
    private function starsLabel(Entry $entry): ?string
    {
        if ($entry->stars->value()) {
            $label = $entry->stars->label();
        } else {
            $originalId = $entry->value('original_review');
            $original = $originalId ? EntryFacade::find($originalId) : null;
            $label = $original?->stars->value() ? $original->stars->label() : null;
        }

        if ($label === null) {
            return null;
        }

        return str_replace('☆', '', $label) ?: $label;
    }

    /**
     * The numeric rating, inheriting from the original review for restagings. Public because
     * App\Fringe\ReviewScraper reuses it when the pasted URL is one of our own reviews —
     * there is one definition of what a review's rating is.
     */
    public function starsValue(Entry $entry): ?float
    {
        if ($entry->stars->value() !== null && $entry->stars->value() !== '') {
            return (float) $entry->stars->value();
        }

        $originalId = $entry->value('original_review');
        $originalId = is_array($originalId) ? ($originalId[0] ?? null) : $originalId;
        $original = $originalId ? EntryFacade::find($originalId) : null;
        $value = $original?->stars->value();

        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * The review body (own, or the original's for restagings) split into sentences,
     * so any line can be picked as the quote — including the last one. Public for the same
     * reason as starsValue.
     */
    /**
     * The review's prose, as sentences grouped by paragraph — the shape the quote picker
     * needs so it can show the review as it reads. Splitting is delegated to ReviewScraper
     * so one of Troy's reviews and a scraped one behave identically in the picker.
     *
     * @return string[][]
     */
    public function quotableParagraphs(Entry $entry): array
    {
        $body = $entry->value('content');

        if (! $body && ($originalId = $entry->value('original_review'))) {
            $body = EntryFacade::find($originalId)?->value('content');
        }

        $text = is_array($body) ? $this->bardText($body) : trim(strip_tags((string) $body));

        return app(ReviewScraper::class)->sentencesForParagraphs(
            collect(preg_split('/\n+/', trim($text)))
                ->map(fn ($paragraph) => trim($paragraph))
                ->filter()
                ->values()
                ->all()
        );
    }

    /**
     * Plain text from Bard content; review-reference pins become the referenced show's title.
     */
    private function bardText(array $nodes): string
    {
        return collect($nodes)->map(function ($node) {
            $type = $node['type'] ?? '';

            if ($type === 'text') {
                return $node['text'] ?? '';
            }

            if ($type === 'btsPin') {
                $id = $node['attrs']['values']['review'] ?? null;
                $id = is_array($id) ? ($id[0] ?? null) : $id;

                return $id ? (EntryFacade::find($id)?->value('title') ?? '') : '';
            }

            $inner = $this->bardText($node['content'] ?? []);

            return in_array($type, ['paragraph', 'heading']) ? $inner."\n" : $inner;
        })->implode('');
    }

    private function uploadBackground(Entry $entry, Request $request): string
    {
        $file = $request->file('image');
        $path = 'fringe/share-cards/'.$entry->slug().'.'.strtolower($file->getClientOriginalExtension());

        $container = AssetContainer::findByHandle('assets');

        // Replace any previous upload for this review
        $container->asset($path)?->delete();
        $container->makeAsset($path)->upload($file);

        return $path;
    }
}
