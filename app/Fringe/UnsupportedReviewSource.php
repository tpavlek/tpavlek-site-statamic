<?php

namespace App\Fringe;

use RuntimeException;

/**
 * The URL isn't from a publication we know how to read. Carries a message written for the
 * person who pasted it, so the generator can show it as-is.
 */
class UnsupportedReviewSource extends RuntimeException
{
}
