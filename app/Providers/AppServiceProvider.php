<?php

namespace App\Providers;

use App\Fieldtypes\VideoDistribution;
use App\Fringe\FestivalUrls;
use App\Http\Controllers\CP\VideoDistributionController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Collection;
use Statamic\Hooks\CP\EntriesIndexQuery;
use Statamic\Statamic;
use Statamic\Support\Str;
use Stillat\Relationships\Support\Facades\Relate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        VideoDistribution::register();
        \App\Fieldtypes\TicketImport::register();
        // Powers the review_ref bard pin — same as `entries`, but the dropdown says which
        // festival each review is from. See config/statamic/bard_texstyle.php.
        \App\Fieldtypes\ReviewRef::register();

        // Keep dated entry filenames as YYYY-MM-DD.slug.md.
        //
        // Saving from the CP passes Entry::date() a Carbon, and that branch of the setter
        // returns $date->utc() without the start-of-day normalisation the string branch gets.
        // With a non-UTC app timezone (America/Edmonton) midnight becomes 06:00 UTC, which
        // makes Entry::hasTime() true and stamps the time into the filename
        // (2026-08-22-0600.2026-scratch.md). Re-setting from the date string routes through
        // parseDateFromString, which starts the day properly. Only when the blueprint's date
        // field doesn't want a time — collections that do keep theirs.
        Event::listen(EntrySaving::class, function (EntrySaving $event) {
            $entry = $event->entry;

            if (! $entry->collection()?->dated()) {
                return;
            }

            if ($entry->blueprint()->field('date')?->fieldtype()->timeEnabled()) {
                return;
            }

            if (($date = $entry->date()) && ! $date->isStartOfDay()) {
                $entry->date($date->toDateString());
            }
        });

        // Custom Control Panel assets — the two fieldtypes registered above. Built by
        // `npm run cp:build` from vite-cp.config.js, which must stay in sync with this.
        Statamic::vite('app', [
            'input' => [
                'resources/js/cp.js',
                'resources/css/cp.css',
            ],
            'hotFile' => public_path('cp-hot'),
            'buildDirectory' => 'vendor/app',
        ]);

        Statamic::pushCpRoutes(function () {
            Route::post('fringe-ticket-import', \App\Http\Controllers\CP\TicketImportController::class)->name('fringe-ticket-import');

            Route::prefix('fringe-social-card')->group(function () {
                Route::get('{entryId}', [\App\Http\Controllers\CP\SocialCardController::class, 'show'])->name('fringe-social-card.show');
                Route::post('{entryId}', [\App\Http\Controllers\CP\SocialCardController::class, 'save'])->name('fringe-social-card.save');
                Route::post('{entryId}/og-image', [\App\Http\Controllers\CP\SocialCardController::class, 'setOgImage'])->name('fringe-social-card.og');
            });

            Route::prefix('video-distribution')->group(function () {
                Route::get('{entryId}/status', [VideoDistributionController::class, 'status']);
                Route::post('{entryId}/distribute', [VideoDistributionController::class, 'distribute']);
                Route::delete('{entryId}/{platform}/clear', [VideoDistributionController::class, 'clear']);
            });
        });

        // Second sort key for the fringe_reviews CP listing. The collection declares
        // `sort_by: festival` / `sort_dir: desc`, but a collection only gets one, so within
        // a festival the entries would fall back to Stache order. Appending last_modified puts
        // whatever was touched most recently at the top of the current year.
        //
        // Skipped while searching, where the controller deliberately leaves the query unsorted
        // so search relevance survives. Note the key is `last_modified`, not `updated_at` —
        // only the former is queryable on an entry.
        EntriesIndexQuery::hook('query', function ($payload, $next) {
            if ($payload->collection->handle() !== 'fringe_reviews') {
                return $next($payload);
            }

            if (! request('search')) {
                $payload->query->orderBy('last_modified', 'desc');
            }

            // Default the listing to the festival on now. The lineup import puts every show
            // at the festival into this collection, so an unfiltered listing is a couple of
            // hundred entries of which only this year's are being worked on.
            //
            // Backs off the moment the search box or any filter is used, so nothing is ever
            // permanently hidden — looking for a 2024 review still finds it, which wouldn't
            // be true of a constraint applied unconditionally.
            $filtered = request('search') || filled(request('filters'));

            if (! $filtered) {
                $payload->query->where('festival', FestivalUrls::currentSlug());
            }

            return $next($payload);
        });

        Relate::oneToMany(
            'yegvote_2025_candidates.party',
            'municipal_parties.candidates'
        );

        // Review files are named with a festival-year prefix (e.g. 2026-field-zoology-301) so
        // restagings of the same show don't collide, but URLs use the bare show slug since the
        // year is already a segment of the route.
        Collection::computed('fringe_reviews', 'url_slug', function ($entry, $value) {
            return preg_replace('/^\d{4}-/', '', $entry->slug());
        });

        // Fringers navigate by venue number, so a venue always reads "29: Strathcona High
        // School". The number is stored separately from the name so that renumbering a venue,
        // or a new sponsor in its name, doesn't strand the notes on an orphaned entry.
        Collection::computed('venues', 'display_name', function ($entry, $value) {
            $number = $entry->value('number');

            return $number ? "{$number}: {$entry->value('title')}" : $entry->value('title');
        });

        Collection::computed('endorsements', 'og_title', function ($entry, $value) {
            return "{$entry->title} for {$entry->ward->slug} - Endorsement by Troy Pavlek";
        });

        Collection::computed('endorsements', 'og_description', function ($entry, $value) {
            return "Read why {$entry->title} is the best vote for {$entry->ward->slug} in the 2025 Edmonton municipal election";
        });

        Collection::computed('endorsements', 'endorsement_image', function ($entry, $value) {
            return $entry->sharable_image;
        });

        Collection::computed('fringe_reviews', 'og_title', function ($entry, $value) {
            if ($value) {
                return $value;
            }

            // ->value(), not the Value object itself, which is truthy even when unrated.
            if ($entry->stars?->value()) {
                return "$entry->title — {$entry->stars->label()} (Edmonton Fringe Review by Troy Pavlek)";
            }

            return "{$entry->title} (Edmonton Fringe Review by Troy Pavlek)";
        });

        Collection::computed('fringe_reviews', 'og_description', function ($entry, $value) {
            if ($value) {
                return $value;
            }

            $year = $entry->festival?->slug() ?? '';

            if ($entry->stars?->value()) {
                return "Read why {$entry->title} earned {$entry->stars->label()} from Troy Pavlek at the {$year} Edmonton International Fringe Festival";
            }

            return "Read Troy's review of {$entry->title} at the {$year} Edmonton International Fringe Festival";
        });

        Collection::computed('fringe_reviews', 'review_og_image', function ($entry, $value) {
            if ($value) {
                return $value;
            }

            return [ 'url' => "https://troypavlek.ca/assets/og-fringe-reviews.jpeg" ];
        });

        Collection::computed('fringe_reviews', 'review_schema', function ($entry, $value) {
            return \App\Schema\FringeReviewSchema::build($entry);
        });

        // The trail rendered by fringe/_breadcrumbs, and the BreadcrumbList markup that
        // mirrors it. A show page is a controller-less entry route, so unlike the reviews
        // index there's nowhere but a computed field to build this.
        Collection::computed('fringe_reviews', 'breadcrumbs', function ($entry, $value) {
            return \App\Schema\BreadcrumbSchema::forReview($entry);
        });

        Collection::computed('fringe_reviews', 'breadcrumb_schema', function ($entry, $value) {
            return \App\Schema\BreadcrumbSchema::build(\App\Schema\BreadcrumbSchema::forReview($entry));
        });

        // The artist's own page, when they have one — only companies with a real name and
        // more than one reviewed show do. Null otherwise, so the template links the name
        // rather than printing a dead link for the other 42 artists. See App\Fringe\Artists.
        Collection::computed('fringe_reviews', 'artist_page_url', function ($entry, $value) {
            $id = $entry->value('artist');
            $id = is_array($id) ? ($id[0] ?? null) : $id;
            $artist = $id ? \Statamic\Facades\Entry::find($id) : null;

            if (! $artist) {
                return null;
            }

            return \App\Fringe\Artists::find(\App\Fringe\Artists::slug($artist))
                ? \App\Fringe\Artists::url($artist)
                : null;
        });

        // Publication date, and an update stamp only when there really was a later edit —
        // see App\Fringe\ReviewFreshness.
        Collection::computed('fringe_reviews', 'freshness', function ($entry, $value) {
            return \App\Fringe\ReviewFreshness::for($entry);
        });

        // The stars to show, and the year they were earned — see App\Fringe\ReviewRating.
        // Templates that link to a review should use this rather than reaching for `stars`
        // and `festival` separately, which is how an inherited rating ended up wearing the
        // wrong year.
        Collection::computed('fringe_reviews', 'rating', function ($entry, $value) {
            return \App\Fringe\ReviewRating::for($entry);
        });

        // The company's earlier Fringe shows. Computed rather than picked by hand — see
        // App\Fringe\ArtistShows for why the CP can't scope a picker to a sibling field.
        // Lazy, like every computed field, so the reviews index doesn't pay for it.
        Collection::computed('fringe_reviews', 'previous_shows', function ($entry, $value) {
            return \App\Fringe\ArtistShows::previous($entry);
        });

        // Posts get schema.org markup automatically when they reference reviews.
        Collection::computed('posts', 'post_schema', function ($entry, $value) {
            return \App\Schema\PostSchema::build($entry);
        });

        Collection::computed('posts', 'og_type', function ($entry, $value) {
            return 'article';
        });

        // Which Fringe festivals a post covers — the explicit fringe_festival terms if it has
        // any, otherwise derived from the shows it headlines. See App\Fringe\PostFestivals.
        // Not queryable (computed fields never are), so callers filter in PHP.
        Collection::computed('posts', 'festivals', function ($entry, $value) {
            return \App\Fringe\PostFestivals::for($entry);
        });

        // Each festival's reviews page is a controller route rather than an entry, so the
        // sitemap generator can't discover it. One per fringe_festival term, which means a
        // new year appears automatically.
        //
        // FestivalUrls::reviews() resolves the current year to /fringe/reviews, so the map
        // only ever emits URLs that actually serve a page. Listing one that redirects just
        // asks Google to discard the entry.
        \Pecotamic\Sitemap\Sitemap::addEntries(function () {
            return \Statamic\Facades\Term::query()
                ->where('taxonomy', 'fringe_festival')
                ->get()
                ->map(fn ($term) => new \Pecotamic\Sitemap\SitemapEntry(
                    FestivalUrls::reviews($term->slug()),
                    $term->lastModified() ?? new \DateTime,
                ))
                ->all();
        });

        // Artist pages, likewise controller routes. The list is derived from the reviews, so
        // a company earns a sitemap entry the moment their second review is published and
        // loses nothing if they never come back.
        \Pecotamic\Sitemap\Sitemap::addEntries(function () {
            $artists = \App\Fringe\Artists::withPages();

            return $artists
                ->map(fn ($artist) => new \Pecotamic\Sitemap\SitemapEntry(
                    \App\Fringe\Artists::url($artist),
                    \App\Fringe\Artists::reviews($artist)
                        ->map->lastModified()
                        ->filter()
                        ->max() ?? new \DateTime,
                ))
                ->push(new \Pecotamic\Sitemap\SitemapEntry('/fringe/artists', new \DateTime))
                ->all();
        });

        // The public share-card generator is a controller route, so the sitemap can't find
        // it on its own. It's a free tool for artists, worth being findable.
        \Pecotamic\Sitemap\Sitemap::addEntries(fn () => [
            new \Pecotamic\Sitemap\SitemapEntry(
                FestivalUrls::absolute('/fringe/social-review-generator'),
                new \DateTime,
            ),
        ]);
    }
}
