<?php

namespace App\Console\Commands;

use App\Http\Controllers\OgCarouselController;
use App\Og\CardRenderer;
use Illuminate\Console\Command;

/**
 * Rasterises the Instagram carousel defined in storage/app/private/og/carousel.json:
 * one 1080x1080 PNG per slide, written to storage/app/tmp/carousel/. Preview a slide at
 * /og-carousel/{n} first — the command screenshots exactly that page.
 *
 * Nothing is registered as an asset and nothing lands in public/ — carousel slides are
 * only ever uploaded to Instagram by hand, same reasoning as the CP "Download Instagram
 * image" action.
 */
class FringeCarousel extends Command
{
    protected $signature = 'fringe:carousel {--slide= : Render only this slide number}';

    protected $description = 'Render the Instagram carousel slides from og/carousel.json';

    public function handle(CardRenderer $renderer, OgCarouselController $controller): int
    {
        $slides = $controller->slides();

        if ($slides === []) {
            $this->error('No slides. Define them in storage/app/private/'.OgCarouselController::PATH);

            return self::FAILURE;
        }

        $only = $this->option('slide');
        $origin = rtrim(config('app.url'), '/');

        foreach (array_keys($slides) as $i) {
            if ($only !== null && (int) $only !== $i) {
                continue;
            }

            $out = storage_path("app/tmp/carousel/slide-{$i}.png");
            $renderer->captureTo("{$origin}/og-carousel/{$i}", $out, 'square');
            $this->info("Slide {$i}: {$out}");
        }

        return self::SUCCESS;
    }
}
