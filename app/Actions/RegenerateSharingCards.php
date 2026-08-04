<?php

namespace App\Actions;

use App\Og\CardParams;
use App\Og\CardRenderer;
use RuntimeException;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry as EntryContract;

use function Statamic\trans as __;

/**
 * "Regenerate link preview" on a post, in the CP.
 *
 * The card takes its copy from the entry's own og_title and og_description, so editing
 * either one leaves the image saying something the page no longer says. Rebuilding it is
 * therefore an editing step, and it belongs next to the editing rather than in a terminal.
 *
 * This one writes to public/assets and points og_image at the result, because the link
 * preview is the page's own image and has to be on the server for a crawler to fetch. The
 * Instagram square is the opposite case and gets its own action, DownloadInstagramCard.
 *
 * Rendering is App\Og\CardRenderer, shared with `php artisan og:card`.
 */
class RegenerateSharingCards extends Action
{
    protected $icon = 'image';

    public static function title()
    {
        return __('Regenerate link preview');
    }

    public function visibleTo($item)
    {
        return $item instanceof EntryContract && $item->collection()?->handle() === 'posts';
    }

    /** Each card is built from one entry's copy; a bulk run is just a way to clobber the wrong one. */
    public function visibleToBulk($items)
    {
        return false;
    }

    public function buttonText()
    {
        return __('Regenerate');
    }

    public function run($items, $values)
    {
        $entry = $items->first();

        if (! $entry) {
            return __('Nothing selected.');
        }

        $renderer = app(CardRenderer::class);

        if (! $renderer->chrome()) {
            // A deploy target usually has no browser. Saying so beats a 500 that looks like
            // the card generator is broken.
            throw new RuntimeException(__('Headless Chrome is not available on this server, so cards can only be generated locally.'));
        }

        $path = CardParams::path($entry->slug());

        $renderer->render(['entry' => $entry->id()], $path);

        // set()->save(), not a CP update request: an entry update replaces the entry's data
        // with exactly what was submitted, so a partial payload deletes every field it omits.
        // This merges into what is already there.
        $entry->set('og_image', $path)->save();

        return __('Rebuilt :file', ['file' => $path]);
    }
}
