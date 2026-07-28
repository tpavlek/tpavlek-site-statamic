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
    'title' => 'Edmonton Fringe Festival Reviews',
    'og_title' => 'Edmonton Fringe Festival Reviews',
    'og_description' => 'Reviews of every show I see at the Edmonton International Fringe Theatre Festival, so you can find a good one.',
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
|   /fringe/reviews                      302 to whichever festival is current
|   /fringe/{year}/reviews               that year's reviews
|   /fringe/{year}/reviews/{slug}        a single show (see fringe_reviews.yaml)
|
| Adding a festival year means creating the fringe_festival term. Nothing here
| or in the controller needs touching.
|
*/

$year = ['year' => '[0-9]{4}'];

Route::get('/fringe/reviews', [ FringeController::class, 'currentYear' ]);
Route::get('/fringe/{year}/reviews', [ FringeController::class, 'year' ])->where($year);
Route::get('/fringe/{festival}/reviews/{slug}/share-card', [ \App\Http\Controllers\CP\SocialCardController::class, 'publicShow' ])
    ->where(['festival' => '[0-9]{4}']);

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
