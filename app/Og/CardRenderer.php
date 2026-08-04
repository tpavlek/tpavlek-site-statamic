<?php

namespace App\Og;

use RuntimeException;
use Statamic\Facades\Asset as AssetFacade;
use Symfony\Component\Process\Process;

/**
 * Rasterises the /og-card page into a PNG.
 *
 * Shared by `php artisan og:card` and the "Regenerate sharing cards" action in the CP, so
 * the two cannot drift. The page itself is App\Http\Controllers\OgCardController — the
 * layout lives there and nowhere else, which is why the browser preview and the saved file
 * are always the same thing.
 *
 * Chrome is the dependency to be aware of. It's a given on a laptop and usually absent on
 * a deploy target, so callers should expect the RuntimeException and say something useful
 * rather than letting a 500 out.
 */
class CardRenderer
{
    /**
     * The sizes worth having. `og` is the 1.91:1 that Facebook, LinkedIn, Slack and X all
     * crop toward; `square` is Instagram's feed post.
     */
    public const FORMATS = [
        'og' => [1200, 630],
        'square' => [1080, 1080],
    ];

    /**
     * Renders into public/assets and registers the result as a Statamic asset, so an entry
     * can point at it. For a card that is only ever going to be downloaded, use renderTo():
     * a file nobody links to has no business being in the asset container or the repo.
     *
     * @param  array  $params  copy and artwork for the card, as OgCardController takes them
     * @param  string  $path  destination under public/assets
     * @return string the absolute path written
     */
    public function render(array $params, string $path, string $format = 'og', ?string $origin = null): string
    {
        $destination = $this->renderTo($params, public_path('assets/'.ltrim($path, '/')), $format, $origin);

        $this->register($path, $params['headline'] ?? null);

        return $destination;
    }

    /**
     * Renders to any path, and leaves no trace beyond the file itself.
     *
     * @return string the absolute path written
     */
    public function renderTo(array $params, string $destination, string $format = 'og', ?string $origin = null): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new RuntimeException("Unknown card format [{$format}].");
        }

        [$width, $height] = self::FORMATS[$format];

        $chrome = $this->chrome();

        if (! $chrome) {
            throw new RuntimeException('Could not find Chrome. Set CHROME_BINARY in .env.');
        }

        @mkdir(dirname($destination), 0755, true);

        // Shot at 2x and downsampled rather than taken at final size: Chrome's 1x text
        // rasterisation leaves the serif headline visibly ragged, and every platform
        // re-scales these anyway.
        $shot = tempnam(sys_get_temp_dir(), 'ogcard').'.png';

        $process = new Process([
            $chrome,
            '--headless',
            '--disable-gpu',
            '--hide-scrollbars',
            '--ignore-certificate-errors',
            '--force-device-scale-factor=2',
            '--window-size='.$width.','.$height,
            '--screenshot='.$shot,
            $this->url($params + ['format' => $format], $origin),
        ], timeout: 60);

        $process->run();

        if (! is_file($shot) || filesize($shot) === 0) {
            throw new RuntimeException('Chrome produced no screenshot. '.trim($process->getErrorOutput()));
        }

        $this->downsample($shot, $destination, $width, $height);
        @unlink($shot);

        return $destination;
    }

    public function url(array $params, ?string $origin = null): string
    {
        $origin = rtrim($origin ?: config('app.url'), '/');

        $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== []);

        if (isset($params['images']) && is_array($params['images'])) {
            $params['images'] = implode(',', $params['images']);
        }

        return $origin.'/og-card?'.http_build_query($params);
    }

    /**
     * Writing the PNG into public/assets is not enough to make it a Statamic asset: without
     * metadata Asset::find() returns null, an og_image pointing at it augments to null, and
     * the page quietly falls back to the site-wide default. Saving the asset writes the
     * .meta file the same way an upload would.
     */
    private function register(string $path, ?string $alt): void
    {
        $asset = AssetFacade::make()->container('assets')->path($path);

        if ($alt) {
            $asset->set('alt', $alt);
        }

        $asset->save();
    }

    private function downsample(string $source, string $destination, int $width, int $height): void
    {
        $shot = imagecreatefrompng($source);
        $out = imagecreatetruecolor($width, $height);

        imagecopyresampled(
            $out, $shot,
            0, 0, 0, 0,
            $width, $height,
            imagesx($shot), imagesy($shot)
        );

        imagepng($out, $destination, 8);
        imagedestroy($out);
        imagedestroy($shot);
    }

    public function chrome(): ?string
    {
        $candidates = array_filter([
            config('services.chrome.binary'),
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
}
