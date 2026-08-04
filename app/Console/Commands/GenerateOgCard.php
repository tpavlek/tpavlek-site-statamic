<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Entry as EntryFacade;
use Symfony\Component\Process\Process;

/**
 * Rasterises the /og-card page into a 1200x630 PNG.
 *
 * The first OpenGraph image on this site was made by pointing headless Chrome at a
 * throwaway HTML file, which worked once and then could never be reproduced. This is that
 * recipe made repeatable: the card is a real page (App\Http\Controllers\OgCardController),
 * the shot is taken at 2x and downsampled so the type has no rasterisation fuzz, and the
 * whole thing runs from one command.
 *
 *   php artisan og:card --entry=six-shows-to-watch-at-fringe-2026 --attach
 *   php artisan og:card --headline="Your review deserves better than a screenshot." \
 *                       --eyebrow="Troy's Fringe Reviews" --out=og-social-review-generator.png
 *
 * Preview any of it in a browser first at /og-card with the same values as query params.
 */
class GenerateOgCard extends Command
{
    protected $signature = 'og:card
        {--entry= : Entry id or slug to take the copy and artwork from}
        {--headline= : Overrides the entry\'s og_title}
        {--eyebrow= : Small label above the headline}
        {--subhead= : Overrides the entry\'s og_description}
        {--footnote= : Small line under the subhead}
        {--images=* : Asset paths (or /site-root paths), up to three, fanned on the right}
        {--out= : Output path under public/assets. Defaults to og/{entry-slug}.png}
        {--attach : Set the entry\'s og_image to the generated file}
        {--url= : Site origin to screenshot. Defaults to the app URL}';

    protected $description = 'Render an OpenGraph card to a 1200x630 PNG';

    private const WIDTH = 1200;

    private const HEIGHT = 630;

    public function handle(): int
    {
        if (! ($chrome = $this->chrome())) {
            $this->error('Could not find Chrome. Set CHROME_BINARY in .env.');

            return self::FAILURE;
        }

        $entry = $this->entry();

        if ($this->option('entry') && ! $entry) {
            $this->error('No entry matching '.$this->option('entry'));

            return self::FAILURE;
        }

        $relative = ltrim($this->option('out') ?: 'og/'.($entry?->slug() ?? 'card').'.png', '/');
        $destination = public_path('assets/'.$relative);

        @mkdir(dirname($destination), 0755, true);

        $this->line('Rendering '.$this->cardUrl());

        // --force-device-scale-factor=2 then a downsample, rather than shooting at 1200x630
        // directly: Chrome's own text rasterisation at 1x leaves the serif headline visibly
        // ragged, and every platform re-scales OG images anyway.
        $shot = tempnam(sys_get_temp_dir(), 'ogcard').'.png';

        $process = new Process([
            $chrome,
            '--headless',
            '--disable-gpu',
            '--hide-scrollbars',
            '--ignore-certificate-errors',
            '--force-device-scale-factor=2',
            '--window-size='.self::WIDTH.','.self::HEIGHT,
            '--screenshot='.$shot,
            $this->cardUrl(),
        ], timeout: 60);

        $process->run();

        if (! is_file($shot) || filesize($shot) === 0) {
            $this->error('Chrome produced no screenshot.');
            $this->line($process->getErrorOutput());

            return self::FAILURE;
        }

        $this->downsample($shot, $destination);
        @unlink($shot);

        $this->register($relative, $this->option('headline') ?: $entry?->value('og_title'));

        $this->info('Wrote '.$destination.' ('.self::WIDTH.'x'.self::HEIGHT.', '.$this->kb($destination).' KB)');

        if ($this->option('attach')) {
            if (! $entry) {
                $this->warn('--attach needs --entry. Skipped.');

                return self::SUCCESS;
            }

            // set()->save(), never a CP update request: an entry update replaces the entry's
            // data with exactly what was submitted, so a partial payload deletes every field
            // it omits. This merges into what is already there.
            $entry->set('og_image', $relative)->save();

            $this->info('Set og_image on '.$entry->slug().' to '.$relative);
        }

        return self::SUCCESS;
    }

    private function cardUrl(): string
    {
        $origin = rtrim($this->option('url') ?: config('app.url'), '/');

        $params = array_filter([
            'entry' => $this->option('entry'),
            'headline' => $this->option('headline'),
            'eyebrow' => $this->option('eyebrow'),
            'subhead' => $this->option('subhead'),
            'footnote' => $this->option('footnote'),
            'images' => implode(',', $this->option('images')) ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        return $origin.'/og-card?'.http_build_query($params);
    }

    /**
     * Writing the PNG into public/assets is not enough to make it an asset: Statamic only
     * knows about files it has metadata for, so an og_image pointing at an unregistered path
     * augments to null and the page quietly falls back to the site-wide default. Saving the
     * asset writes the .meta file the same way an upload would.
     */
    private function register(string $path, ?string $alt): void
    {
        $asset = AssetFacade::make()->container('assets')->path($path);

        if ($alt) {
            $asset->set('alt', $alt);
        }

        $asset->save();
    }

    private function downsample(string $source, string $destination): void
    {
        $shot = imagecreatefrompng($source);
        $out = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        imagecopyresampled(
            $out, $shot,
            0, 0, 0, 0,
            self::WIDTH, self::HEIGHT,
            imagesx($shot), imagesy($shot)
        );

        imagepng($out, $destination, 8);
        imagedestroy($out);
        imagedestroy($shot);
    }

    private function entry()
    {
        if (! ($id = $this->option('entry'))) {
            return null;
        }

        return EntryFacade::find($id)
            ?? EntryFacade::query()->get()->first(fn ($e) => $e->slug() === $id);
    }

    private function chrome(): ?string
    {
        $candidates = array_filter([
            env('CHROME_BINARY'),
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
        ]);

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function kb(string $path): int
    {
        return (int) round(filesize($path) / 1024);
    }
}
