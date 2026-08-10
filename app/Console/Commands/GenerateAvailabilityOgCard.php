<?php

namespace App\Console\Commands;

use App\Fringe\FestivalUrls;
use App\Og\CardRenderer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Build the ticket-availability page's OpenGraph image: the site's branded card (green
 * doodle background, eyebrow + headline), with a small screenshot of the page's actual
 * availability rows as the fan artwork beside the copy.
 *
 * Two shots, both taken logged out (no cookies), so they're the public view — buckets, no
 * seat numbers. First a square crop of the rows (below the nav/instructions, so it shows
 * real availabilities rather than the header); then the /og-card page composed with it. Re-run
 * when the page or the copy changes.
 *
 *   php artisan fringe:og-availability
 *   php artisan fringe:og-availability --url=https://troypavlek.ca
 */
class GenerateAvailabilityOgCard extends Command
{
    protected $signature = 'fringe:og-availability
        {--url= : Site origin to screenshot. Defaults to the app URL.}';

    protected $description = 'Render the ticket-availability page into its OpenGraph image.';

    public const PATH = 'og/ticket-availability.png';

    private const SHOT_PATH = 'og/ticket-availability-shot.png';

    public function handle(CardRenderer $renderer): int
    {
        $origin = rtrim($this->option('url') ?: config('app.url'), '/');
        $url = $origin.'/fringe/ticket-availability';
        $year = FestivalUrls::currentSlug();

        $this->line('Screenshotting availability rows from '.$url);

        try {
            // A square slice of the rows, cropped from below the nav/heading/instructions so
            // the thumbnail shows actual showtimes and statuses.
            $renderer->captureCrop($url, public_path('assets/'.self::SHOT_PATH), 1200, 1300, 80, 500, 560);

            // Compose the branded card with that slice fanned beside the copy.
            $destination = $renderer->render([
                'eyebrow' => "Edmonton Fringe {$year}",
                'headline' => 'See which shows are selling out',
                'subhead' => 'Showtimes and remaining availability for every show at the Fringe.',
                'images' => ['/assets/'.self::SHOT_PATH],
            ], self::PATH, 'og', $origin);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Wrote '.$destination);

        return self::SUCCESS;
    }
}
