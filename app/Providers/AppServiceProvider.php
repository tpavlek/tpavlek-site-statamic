<?php

namespace App\Providers;

use App\Fieldtypes\VideoDistribution;
use App\Http\Controllers\CP\VideoDistributionController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Statamic\Facades\Collection;
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

        Statamic::vite('video-distribution', [
            'input' => ['resources/js/cp.js'],
            'publicDirectory' => 'public',
            'buildDirectory' => 'build',
        ]);

        Statamic::pushCpRoutes(function () {
            Route::post('fringe-ticket-import', \App\Http\Controllers\CP\TicketImportController::class)->name('fringe-ticket-import');

            Route::prefix('fringe-social-card')->group(function () {
                Route::get('{entryId}', [\App\Http\Controllers\CP\SocialCardController::class, 'show'])->name('fringe-social-card.show');
                Route::post('{entryId}', [\App\Http\Controllers\CP\SocialCardController::class, 'save'])->name('fringe-social-card.save');
            });

            Route::prefix('video-distribution')->group(function () {
                Route::get('{entryId}/status', [VideoDistributionController::class, 'status']);
                Route::post('{entryId}/distribute', [VideoDistributionController::class, 'distribute']);
                Route::delete('{entryId}/{platform}/clear', [VideoDistributionController::class, 'clear']);
            });
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
    }
}
