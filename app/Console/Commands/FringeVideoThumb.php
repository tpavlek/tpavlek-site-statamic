<?php

namespace App\Console\Commands;

use App\Og\CardRenderer;
use Illuminate\Console\Command;

/**
 * Rasterises the /og-story page into a 1080x1920 PNG for a social media video thumbnail —
 * one per festival day. Preview at /og-story?day=1&shows[]=... first; the command
 * screenshots exactly that page.
 *
 * Nothing is registered as an asset and nothing lands in public/ — a video thumbnail is
 * only ever uploaded by hand, same reasoning as the carousel slides and the CP "Download
 * Instagram image" action.
 */
class FringeVideoThumb extends Command
{
    protected $signature = 'fringe:video-thumb
        {--day=1 : The festival day, for the headline and the filename}
        {--show=* : A review slug, a title fragment, or "Title|4.5" (repeatable)}
        {--photo= : Asset path for the framed photo (default: the fringe-with-atlas shot)}
        {--focus= : CSS object-position for the photo crop}';

    protected $description = 'Render a 1080x1920 video thumbnail for a Fringe day';

    public function handle(CardRenderer $renderer): int
    {
        $day = (string) $this->option('day');

        $query = http_build_query(array_filter([
            'day' => $day,
            'shows' => $this->option('show'),
            'photo' => $this->option('photo'),
            'focus' => $this->option('focus'),
        ]));

        $origin = rtrim(config('app.url'), '/');
        $slug = preg_replace('~[^a-z0-9]+~', '-', mb_strtolower($day));
        $out = storage_path("app/tmp/video-thumbs/fringe-day-{$slug}.png");

        $renderer->captureTo("{$origin}/og-story?{$query}", $out, 'story');

        $this->info($out);

        return self::SUCCESS;
    }
}
