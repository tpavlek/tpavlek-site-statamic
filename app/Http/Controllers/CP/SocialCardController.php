<?php

namespace App\Http\Controllers\CP;

use App\Http\Controllers\Controller;
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

    private function builder(Entry $entry, string $backUrl, string $backLabel)
    {
        $options = collect($entry->value('share_card_options') ?? []);
        $lines = $this->reviewLines($entry);
        $stars = $this->starsLabel($entry);

        return view('cp.fringe-social-card', [
            'entry' => $entry,
            'title' => $entry->value('title'),
            'stars' => $stars,
            'watchlist' => ! $stars && $entry->recommendation->value() === 'watchlist',
            'quote' => $entry->value('share_quote') ?: ($lines[0] ?? ''),
            'reviewLines' => $lines,
            'posterUrl' => $entry->poster?->url(),
            'shareImageUrl' => $entry->share_image?->url(),
            'options' => [
                'position' => (int) $options->get('position', 100),
                'focal_x' => (int) $options->get('focal_x', 50),
                'focal_y' => (int) $options->get('focal_y', 50),
                'text_size' => (int) $options->get('text_size', 42),
                'attribution_enabled' => (bool) $options->get('attribution_enabled', true),
                'attribution_text' => (string) $options->get('attribution_text', "\u{2014} Troy's Fringe Reviews"),
                'attribution_size' => (int) $options->get('attribution_size', 34),
            ],
            'canSave' => UserFacade::current() !== null,
            'saveUrl' => cp_route('fringe-social-card.save', $entry->id()),
            'ogUrl' => cp_route('fringe-social-card.og', $entry->id()),
            'backUrl' => $backUrl,
            'backLabel' => $backLabel,
        ]);
    }

    public function save(string $entryId, Request $request)
    {
        $entry = $this->findReview($entryId);

        $request->validate([
            'quote' => 'required|string|max:280',
            'position' => 'required|integer|between:0,100',
            'focal_x' => 'required|integer|between:0,100',
            'focal_y' => 'required|integer|between:0,100',
            'text_size' => 'required|integer|between:20,150',
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
                'text_size' => (int) $request->input('text_size'),
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
            'text_size' => 'Text size',
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
     * The review body (own, or the original's for restagings) split into sentences,
     * so any line can be picked as the quote — including the last one.
     */
    private function reviewLines(Entry $entry): array
    {
        $body = $entry->value('content');

        if (! $body && ($originalId = $entry->value('original_review'))) {
            $body = EntryFacade::find($originalId)?->value('content');
        }

        $text = is_array($body) ? $this->bardText($body) : trim(strip_tags((string) $body));

        return collect(preg_split('/\n+/', trim($text)))
            ->flatMap(fn ($paragraph) => preg_split('/(?<=[.!?])\s+/', trim($paragraph)) ?: [])
            ->map(fn ($sentence) => trim($sentence))
            ->filter()
            ->values()
            ->all();
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
