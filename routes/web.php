<?php

use App\Http\Controllers\EndorsementsController;
use App\Http\Controllers\FringeController;
use App\Http\Controllers\YegvoteController;
use App\Http\Controllers\YouTubeOAuthController;
use Illuminate\Support\Facades\Route;

// Route::statamic('example', 'example-view', [
//    'title' => 'Example'
// ]);
//

Route::statamic('/videos', 'videos/index', ['title' => 'Videos']);
Route::statamic('/fringe', 'fringe/landing', [
    'title' => 'Edmonton Fringe Reviews',
    'og_title' => 'Edmonton Fringe Reviews',
    'og_description' => 'Reviews of every show I see at the Edmonton International Fringe Theatre Festival, so you can find a good one.',
    // Without this the layout falls through to the site-wide default, which is a
    // screenshot of the 2025 election candidate list.
    'og_image' => ['url' => 'https://troypavlek.ca/assets/og-fringe-reviews.jpeg'],
]);

Route::get('/endorsements', fn() => redirect('/yegvote-2025/endorsements'));

// Retired: the landing page and the reviews intro cover this better. Redirected rather
// than 404'd so anything already linking or indexed lands somewhere useful.
Route::redirect('/fringe/why-fringe', '/fringe', 301);

/*
|--------------------------------------------------------------------------
| Fringe
|--------------------------------------------------------------------------
|
| Everything Fringe lives under /fringe:
|
|   /fringe                              landing page
|   /fringe/reviews                      the current festival's reviews (200)
|   /fringe/{year}                       301 to that year's reviews
|   /fringe/{year}/reviews               that year's reviews, or 302 to /fringe/reviews
|                                        while that year is the current festival
|   /fringe/{year}/reviews/{slug}        a single show (see fringe_reviews.yaml)
|
| The current festival's reviews exist at exactly one URL, /fringe/reviews, so the one
| URL that never changes is the one that ranks for "edmonton fringe reviews" and friends.
| The year URL redirects there rather than serving a copy that points its canonical
| elsewhere: a redirect is a directive, a canonical is only a hint, so this consolidates
| the signals instead of asking Google to. Once a year stops being current its URL starts
| serving its own archive, which is why that redirect is a 302.
|
| Adding a festival year means creating the fringe_festival term. Nothing here
| or in the controller needs touching.
|
*/

$year = ['year' => '[0-9]{4}'];

Route::get('/fringe/reviews', [ FringeController::class, 'currentYear' ]);
Route::get('/fringe/{year}/reviews', [ FringeController::class, 'year' ])->where($year);

// There has never been a festival landing page at /fringe/{year}, but it's the obvious
// thing to type, and a 404 is a bad answer to a good guess. Permanent because it's true
// regardless of which festival is current: the year's reviews are always one level down.
// Unknown years 404 here rather than redirecting to a URL that would 404 anyway.
Route::get('/fringe/{year}', function (string $year) {
    abort_if(! \Statamic\Facades\Term::find("fringe_festival::{$year}"), 404);

    return redirect("/fringe/{$year}/reviews", 301);
})->where($year);

Route::get('/fringe/{festival}/reviews/{slug}/share-card', [ \App\Http\Controllers\CP\SocialCardController::class, 'publicShow' ])
    ->where(['festival' => '[0-9]{4}']);

/*
| The public share-card generator: same builder, but for a review published anywhere.
| The build step fetches a URL server-side, so it's throttled — the host allowlist in
| App\Fringe\ReviewScraper is what stops it being pointed somewhere it shouldn't.
*/
Route::get('/fringe/social-review-generator', [\App\Http\Controllers\SocialReviewGeneratorController::class, 'index'])
    ->name('fringe.social-review-generator');
Route::post('/fringe/social-review-generator', [\App\Http\Controllers\SocialReviewGeneratorController::class, 'build'])
    ->middleware('throttle:20,1')
    ->name('fringe.social-review-generator.build');

/*
| Legacy URLs from before everything moved under /fringe. All 301, all still
| linked from the wild, so they stay indefinitely.
|
|   /fringe-{year}                            old, near-empty festival page
|   /fringe-{year}/reviews                    old year index
|   /fringe-{year}/fringe-{year}-reviews      Statamic's filename-derived duplicate
|   /fringe-reviews/{year}/{slug}             old individual review
|   /fringe-reviews/{year}/{slug}/share-card  old share card
*/
Route::get('/fringe-{year}/fringe-{yearAgain}-reviews', fn (string $year) => redirect("/fringe/{$year}/reviews", 301))
    ->where(['year' => '[0-9]{4}', 'yearAgain' => '[0-9]{4}']);

Route::get('/fringe-{year}/reviews', fn (string $year) => redirect("/fringe/{$year}/reviews", 301))->where($year);
Route::get('/fringe-{year}', fn (string $year) => redirect("/fringe/{$year}/reviews", 301))->where($year);

Route::get('/fringe-reviews/{year}/{slug}/share-card', fn (string $year, string $slug) => redirect("/fringe/{$year}/reviews/{$slug}/share-card", 301))->where($year);
Route::get('/fringe-reviews/{year}/{slug}', fn (string $year, string $slug) => redirect("/fringe/{$year}/reviews/{$slug}", 301))->where($year);
Route::get('/yegvote-2025', [ YegvoteController::class, 'yegvote_2025' ]);
Route::get('/yegvote-2025/endorsements', [ EndorsementsController::class, 'currentYear' ]);
//Route::get('/fringe/{entry}/social', [ FringeController::class, 'generateSocialImage' ]);

Route::get('/youtube_oauth_handler', [YouTubeOAuthController::class, 'handle']);

/*
| The OpenGraph card, rendered as a real page so it can be iterated on in a browser.
| `php artisan og:card` screenshots this exact URL, which is what keeps the preview and
| the saved PNG from drifting apart. Noindex via a header on the response.
*/
Route::get('/og-card', \App\Http\Controllers\OgCardController::class)->name('og-card');
