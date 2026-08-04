<?php

namespace App\Og;

/**
 * Where a generated sharing card lives.
 *
 * One place, because the console command writes the file, the CP action writes it and then
 * points `og_image` at it, and a mismatch between those two would look like the card simply
 * hadn't regenerated.
 */
class CardParams
{
    public static function path(string $slug, string $format = 'og'): string
    {
        // The square card is a companion to the link preview rather than a different card,
        // so it sits beside it under the same name with a suffix.
        return 'og/'.$slug.($format === 'og' ? '' : '-'.$format).'.png';
    }
}
