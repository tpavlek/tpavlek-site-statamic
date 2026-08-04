<?php

namespace App\Console\Commands;

use App\Og\CardParams;
use App\Og\CardRenderer;
use Illuminate\Console\Command;
use RuntimeException;
use Statamic\Facades\Entry as EntryFacade;

/**
 * Renders a sharing card to a PNG.
 *
 * The first OpenGraph image on this site was made by pointing headless Chrome at a
 * throwaway HTML file, which worked once and then could never be reproduced. This is that
 * recipe made repeatable. The card is a real page (App\Http\Controllers\OgCardController)
 * and App\Og\CardRenderer takes the shot, so the browser preview at /og-card and the saved
 * file are the same thing.
 *
 *   php artisan og:card --entry=six-shows-to-watch-at-fringe-2026 --attach
 *   php artisan og:card --entry=six-shows-to-watch-at-fringe-2026 --format=square
 *   php artisan og:card --headline="Your review deserves better than a screenshot." \
 *                       --eyebrow="Troy's Fringe Reviews" --out=og-social-review-generator.png
 *
 * The same thing is available in the CP as the "Regenerate sharing cards" action on a post,
 * which is the easier route after editing an entry's description.
 */
class GenerateOgCard extends Command
{
    protected $signature = 'og:card
        {--entry= : Entry id or slug to take the copy and artwork from}
        {--format=og : og (1200x630 link preview) or square (1080x1080 for Instagram)}
        {--headline= : Overrides the entry\'s og_title}
        {--eyebrow= : Small label above the headline}
        {--subhead= : Overrides the entry\'s og_description}
        {--footnote= : Small line under the subhead}
        {--images=* : Asset paths (or /site-root paths), up to three, fanned alongside}
        {--out= : Output path under public/assets. Defaults to og/{entry-slug}.png}
        {--attach : Set the entry\'s og_image to the generated file}
        {--url= : Site origin to screenshot. Defaults to the app URL}';

    protected $description = 'Render a sharing card (OpenGraph or Instagram) to a PNG';

    public function handle(CardRenderer $renderer): int
    {
        $entry = $this->entry();

        if ($this->option('entry') && ! $entry) {
            $this->error('No entry matching '.$this->option('entry'));

            return self::FAILURE;
        }

        $format = $this->option('format');

        if (! isset(CardRenderer::FORMATS[$format])) {
            $this->error('Unknown format ['.$format.']. Expected one of: '.implode(', ', array_keys(CardRenderer::FORMATS)).'.');

            return self::FAILURE;
        }

        $params = array_filter([
            'entry' => $this->option('entry'),
            'headline' => $this->option('headline'),
            'eyebrow' => $this->option('eyebrow'),
            'subhead' => $this->option('subhead'),
            'footnote' => $this->option('footnote'),
            'images' => $this->option('images'),
        ]);

        $path = $this->option('out') ?: CardParams::path($entry?->slug() ?? 'card', $format);

        $this->line('Rendering '.$renderer->url($params + ['format' => $format], $this->option('url')));

        try {
            $destination = $renderer->render($params, $path, $format, $this->option('url'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        [$width, $height] = CardRenderer::FORMATS[$format];

        $this->info('Wrote '.$destination." ({$width}x{$height}, ".(int) round(filesize($destination) / 1024).' KB)');

        if ($this->option('attach')) {
            if (! $entry) {
                $this->warn('--attach needs --entry. Skipped.');

                return self::SUCCESS;
            }

            if ($format !== 'og') {
                $this->warn('--attach only applies to the og format; og_image left alone.');

                return self::SUCCESS;
            }

            // set()->save(), never a CP update request: an entry update replaces the entry's
            // data with exactly what was submitted, so a partial payload deletes every field
            // it omits. This merges into what is already there.
            $entry->set('og_image', $path)->save();

            $this->info('Set og_image on '.$entry->slug().' to '.$path);
        }

        return self::SUCCESS;
    }

    private function entry()
    {
        if (! ($id = $this->option('entry'))) {
            return null;
        }

        return EntryFacade::find($id)
            ?? EntryFacade::query()->get()->first(fn ($e) => $e->slug() === $id);
    }
}
