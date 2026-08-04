<?php

namespace App\Actions;

use App\Og\CardRenderer;
use RuntimeException;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Support\Str;

use function Statamic\trans as __;

/**
 * "Download Instagram image" on a post: one click, and a 1080x1080 PNG lands in the browser's
 * downloads.
 *
 * Nothing is written to the asset container or the repo. The square card is only ever
 * uploaded to Instagram by hand — no page links to it, no entry points at it — so a copy
 * living in public/assets would be a file that ships on every deploy and gets stale the
 * moment the post's description changes. It renders to a temp file, streams, and deletes
 * itself.
 *
 * `download()` is Statamic's hook for exactly this; Statamic's own DownloadAsset works the
 * same way. Note the controller calls run() first and download() regardless of whether run()
 * threw, so the render happens in run() — where an exception becomes a readable message —
 * and download() only hands over what run() already produced.
 */
class DownloadInstagramCard extends Action
{
    protected $icon = 'download';

    /** One click. There is nothing to confirm and no options to pick. */
    protected $confirm = false;

    private const TMP_DIR = 'app/tmp/instagram-cards';

    private ?string $file = null;

    private ?string $filename = null;

    public static function title()
    {
        return __('Download Instagram image');
    }

    public function visibleTo($item)
    {
        return $item instanceof EntryContract && $item->collection()?->handle() === 'posts';
    }

    /** One post, one image. A zip of several would be a different feature. */
    public function visibleToBulk($items)
    {
        return false;
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

        $this->sweep();

        $this->filename = Str::slug($entry->slug() ?: 'card').'-instagram.png';
        $this->file = $renderer->renderTo(
            ['entry' => $entry->id()],
            storage_path(self::TMP_DIR).'/'.uniqid('ig-', true).'.png',
            'square'
        );

        return __('Downloading :file', ['file' => $this->filename]);
    }

    /**
     * deleteFileAfterSend covers the normal path, but a download the browser abandons leaves
     * half a megabyte behind, and nothing else ever visits this directory. Anything older
     * than an hour has plainly been abandoned.
     */
    private function sweep(): void
    {
        foreach (glob(storage_path(self::TMP_DIR).'/*.png') ?: [] as $stale) {
            if (filemtime($stale) < time() - 3600) {
                @unlink($stale);
            }
        }
    }

    public function download($items, $values)
    {
        if (! $this->file) {
            return false;
        }

        return response()
            ->download($this->file, $this->filename)
            ->deleteFileAfterSend();
    }
}
