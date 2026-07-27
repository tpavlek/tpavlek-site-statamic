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
        $entry = EntryFacade::findByUri("/fringe-reviews/{$festival}/{$slug}");

        abort_unless($entry && $entry->collectionHandle() === 'fringe_reviews', 404);

        return $this->builder($entry, backUrl: $entry->url(), backLabel: 'Back to review');
    }

    private function builder(Entry $entry, string $backUrl, string $backLabel)
    {
        $options = collect($entry->value('share_card_options') ?? []);
        $lines = $this->reviewLines($entry);

        return view('cp.fringe-social-card', [
            'entry' => $entry,
            'title' => $entry->value('title'),
            'stars' => $this->starsLabel($entry),
            'quote' => $entry->value('share_quote') ?: ($lines[0] ?? ''),
            'reviewLines' => $lines,
            'posterUrl' => $entry->poster?->url(),
            'shareImageUrl' => $entry->share_image?->url(),
            'options' => [
                'position' => (int) $options->get('position', 100),
                'focal_x' => (int) $options->get('focal_x', 50),
                'focal_y' => (int) $options->get('focal_y', 50),
                'text_size' => (int) $options->get('text_size', 42),
            ],
            'canSave' => UserFacade::current() !== null,
            'saveUrl' => cp_route('fringe-social-card.save', $entry->id()),
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
            'image' => 'nullable|image|max:10240',
            'clear_image' => 'nullable|boolean',
        ]);

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
            ])
            ->save();

        return redirect(cp_route('fringe-social-card.show', $entryId))
            ->with('success', 'Share card saved.');
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

        return collect(preg_split('/\n+/', trim(strip_tags((string) $body))))
            ->flatMap(fn ($paragraph) => preg_split('/(?<=[.!?])\s+/', trim($paragraph)) ?: [])
            ->map(fn ($sentence) => trim($sentence))
            ->filter()
            ->values()
            ->all();
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
