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
Route::get('/fringe-reviews/{festival}/{slug}/share-card', [ \App\Http\Controllers\CP\SocialCardController::class, 'publicShow' ]);
Route::get('/fringe/reviews', [ FringeController::class, 'currentYear' ]);
Route::get('/fringe-2026/reviews', [ FringeController::class, 'year2026' ]);
Route::get('/fringe-2025/reviews', [ FringeController::class, 'year2025' ]);
Route::get('/fringe-2024/reviews', [ FringeController::class, 'year2024' ]);

// Statamic takes an entry's slug from its filename and ignores the `slug` in front
// matter, so content/collections/pages/fringe-2026-reviews.md is routable at
// /fringe-2026/fringe-2026-reviews — a second, controller-less copy of the reviews page
// with no list, no counts and no structured data. Send those to the real page.
// Two parameter names because Laravel won't accept the same one twice in a URI.
Route::get('/fringe-{year}/fringe-{yearAgain}-reviews', function (string $year) {
    return redirect("/fringe-{$year}/reviews", 301);
})->where(['year' => '[0-9]{4}', 'yearAgain' => '[0-9]{4}']);
Route::get('/yegvote-2025', [ YegvoteController::class, 'yegvote_2025' ]);
Route::get('/yegvote-2025/endorsements', [ EndorsementsController::class, 'currentYear' ]);
//Route::get('/fringe/{entry}/social', [ FringeController::class, 'generateSocialImage' ]);

Route::get('/youtube_oauth_handler', [YouTubeOAuthController::class, 'handle']);
