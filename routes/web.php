<?php

use App\Fringe\FestivalUrls;
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
    'og_image' => ['url' => 'https://troypavlek.ca/assets/og/fringe-reviews.png'],
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

/*
| Feeds. Declared before the reviews routes so /fringe/reviews/feed.xml is never a candidate
| for the {slug} pattern below it.
|
| The reviews feed always covers the current festival, matching /fringe/reviews itself. Past
| years don't get one: a feed of a finished festival never updates again, which is a feed
| that only ever wastes a subscriber's fetches.
*/
Route::get('/feed.xml', [\App\Http\Controllers\FeedController::class, 'posts'])->name('feed.posts');
Route::get('/fringe/reviews/feed.xml', [\App\Http\Controllers\FeedController::class, 'fringeReviews'])->name('feed.fringe');

/*
| Artist pages. Which artists have one is a rule about their reviews rather than a flag on
| the entry — see App\Fringe\Artists — so these are controller routes and the collection has
| no route of its own. An artist who doesn't qualify 404s.
*/
Route::get('/fringe/artists', [\App\Http\Controllers\FringeArtistController::class, 'index'])->name('fringe.artists');
Route::get('/fringe/artists/{slug}', [\App\Http\Controllers\FringeArtistController::class, 'show'])->name('fringe.artist');

// Troy's personal festival schedule, pulled from his public Fringe Google Calendar.
Route::get('/fringe/agenda', [ FringeController::class, 'agenda' ])->name('fringe.agenda');

Route::get('/fringe/ticket-availability', [ FringeController::class, 'soldOut' ])->name('fringe.ticket-availability');

// Admin-only sales leaderboard, nested under ticket-availability (auth enforced in the controller).
Route::get('/fringe/ticket-availability/leaderboard', [ FringeController::class, 'salesLeaderboard' ])->name('fringe.ticket-availability.leaderboard');

// Admin-only on-demand refresh of one show's availability (the refresh button on the report),
// stepped so no single request outlives PHP's execution limit: start plans the run, then one
// performance call per pending showtime, then finish stamps freshness and returns the fresh
// markup. Event/performance ids are posted in the body, not the path — the event id contains
// a colon (601:7454), which is awkward in a URL segment. Auth is enforced in the controller;
// throttled because each call scrapes the ticket site (a long show is ~1 start + N steps, so
// the ceiling is per-minute generous but still a backstop).
Route::post('/fringe/ticket-availability/refresh/start', [ FringeController::class, 'refreshStart' ])
    ->middleware('throttle:60,1')
    ->name('fringe.ticket-availability.refresh.start');
Route::post('/fringe/ticket-availability/refresh/performance', [ FringeController::class, 'refreshPerformance' ])
    ->middleware('throttle:60,1')
    ->name('fringe.ticket-availability.refresh.performance');
Route::post('/fringe/ticket-availability/refresh/finish', [ FringeController::class, 'refreshFinish' ])
    ->middleware('throttle:60,1')
    ->name('fringe.ticket-availability.refresh.finish');

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
|
| The legacy *index* URLs go to the evergreen page, not to their own year's archive.
|
| These were the "Troy's Fringe reviews" URL in their day — before the restructure there was
| no year-agnostic page to link to, so anyone linking to the reviews at all linked to one of
| these. The authority they carry is authority for the undated query, and Search Console says
| so plainly: over July 2026, /fringe-2025/reviews took 85 impressions, and 81 of them were
| "edmonton fringe reviews", "fringe reviews" and "edmonton international fringe festival
| reviews". Three were year-qualified. Meanwhile /fringe/reviews, which is the page those
| searchers actually want, had none at all.
|
| Sending them to /fringe/{year}/reviews spent that authority on an archive that gets a year
| staler every August, and answered an undated question with last year's answer.
|
| The archives keep their own canonical URLs, stay in the sitemap and can still earn the
| dated queries on their own merits — this only changes where the *legacy* paths point.
|
| Individual legacy review URLs below are deliberately untouched: those are about one show,
| and the show's own page is exactly the right destination.
*/
Route::get('/fringe-{year}/fringe-{yearAgain}-reviews', fn () => redirect(FestivalUrls::EVERGREEN, 301))
    ->where(['year' => '[0-9]{4}', 'yearAgain' => '[0-9]{4}']);

Route::get('/fringe-{year}/reviews', fn () => redirect(FestivalUrls::EVERGREEN, 301))->where($year);
Route::get('/fringe-{year}', fn () => redirect(FestivalUrls::EVERGREEN, 301))->where($year);

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
