<?php

namespace App\Providers;

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
        Relate::oneToMany(
            'yegvote_2025_candidates.party',
            'municipal_parties.candidates'
        );

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

            if ($entry->stars) {
                return "$entry->title — {$entry->stars->label()} (Edmonton Fringe Review by Troy Pavlek)";
            }

            return "{$entry->title} (Edmonton Fringe Review by Troy Pavlek)";
        });

        Collection::computed('fringe_reviews', 'og_description', function ($entry, $value) {
            if ($value) {
                return $value;
            }

            if ($entry->stars) {
                return "Read why {$entry->title} earned {$entry->stars->label()} from Troy Pavlek at the 2025 Edmonton International Fringe Festival";
            }

            return "Read Troy's review of {$entry->title} at the 2025 Edmonton International Fringe Festival";
        });

        Collection::computed('fringe_reviews', 'review_og_image', function ($entry, $value) {
            if ($value) {
                return $value;
            }

            return [ 'url' => "https://troypavlek.ca/assets/og-fringe-reviews.jpeg" ];
        });
    }
}
