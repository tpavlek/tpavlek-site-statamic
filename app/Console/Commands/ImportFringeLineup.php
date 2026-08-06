<?php

namespace App\Console\Commands;

use App\Fringe\FestivalUrls;
use App\Fringe\Reviews;
use App\Fringe\TicketPage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Term as TermFacade;

/**
 * Import the whole festival lineup from tickets.fringetheatre.ca.
 *
 * Every show becomes a fringe_reviews entry that is **unpublished** and marked
 * `recommendation: pending` — see App\Fringe\Reviews for what that pair means and why every
 * public listing has to filter on it. The point is not to publish 200 pages; it's to have
 * the lineup as data, so the reviews index can show what else is on, so writing a review is
 * one click rather than an import, and so there's a record of what played each year once the
 * ticket site rotates.
 *
 * Safe to re-run: shows that already have an entry are skipped, matched on the event id in
 * their ticket link rather than on title, because titles change between years.
 *
 *   php artisan fringe:import-lineup              # dry run, writes nothing
 *   php artisan fringe:import-lineup --apply
 *   php artisan fringe:import-lineup --apply --no-posters
 *   php artisan fringe:import-lineup --apply --limit=5
 */
class ImportFringeLineup extends Command
{
    protected $signature = 'fringe:import-lineup
        {--apply : Actually write the entries. Without this it only reports what it would do.}
        {--no-posters : Skip downloading show posters, which is the slow part.}
        {--limit=0 : Only process this many new shows. Useful for a first careful run.}
        {--year= : Festival year. Defaults to the current one.}';

    protected $description = 'Import every show on the ticket site as an unpublished, pending entry.';

    public function handle(): int
    {
        $year = (string) ($this->option('year') ?: FestivalUrls::currentSlug());
        $apply = (bool) $this->option('apply');
        $withPosters = ! $this->option('no-posters');
        $limit = (int) $this->option('limit');

        $festival = TermFacade::find("fringe_festival::{$year}");

        if (! $festival) {
            $this->error("No fringe_festival term for {$year}.");

            return self::FAILURE;
        }

        // Reviews are dated within the festival they cover. An unreviewed import has no date
        // of its own, so it takes the festival's opening day — inside the festival, and
        // stable, so re-running doesn't churn filenames.
        $date = $festival->value('starts_at') ?: "{$year}-08-01";

        $this->line("Festival {$year}, entries dated {$date}.");

        $urls = TicketPage::lineup();

        if (! $urls) {
            $this->error('Could not read the lineup from '.TicketPage::HOST.'/events/.');

            return self::FAILURE;
        }

        $known = Reviews::all()
            ->map(fn (EntryContract $entry) => TicketPage::eventId($entry->value('ticket_link')))
            ->filter()
            ->flip();

        $new = collect($urls)->reject(fn (string $url) => $known->has(TicketPage::eventId($url)))->values();

        $this->line("{$urls[0]} … ".count($urls).' shows listed, '.$new->count().' not yet imported.');

        if ($limit > 0) {
            $new = $new->take($limit);
            $this->comment("Limited to {$limit}.");
        }

        if ($new->isEmpty()) {
            $this->info('Nothing to import.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('Dry run — nothing will be written. Re-run with --apply.');
        }

        $imported = 0;
        $failed = [];
        $bar = $this->output->createProgressBar($new->count());
        $bar->start();

        foreach ($new as $url) {
            $bar->advance();

            $html = TicketPage::fetch($url);

            if ($html === null) {
                $failed[] = "{$url} — no response";

                continue;
            }

            // A dry run must write nothing at all. Resolving a show's artist and venue
            // normally creates those entries when they're new, so both are switched off
            // here — the first version of this command reported "nothing will be written"
            // and then left three artists and four venues behind.
            $fields = TicketPage::fields(
                $html,
                $url,
                withPoster: $apply && $withPosters,
                create: $apply,
            );

            if ($fields === null) {
                $failed[] = "{$url} — could not read show details";

                continue;
            }

            $fields['festival'] = $year;
            $fields['recommendation'] = 'pending';

            if (! $apply) {
                $this->newLine();
                $this->line('  would import: '.$fields['title']);

                $imported++;

                continue;
            }

            EntryFacade::make()
                ->collection('fringe_reviews')
                // Same convention as reviews written by hand: the festival year prefixes the
                // filename so a restaging doesn't collide, and url_slug strips it back off.
                ->slug($year.'-'.Str::slug($fields['title']))
                ->date($date)
                ->published(false)
                ->data($fields)
                ->save();

            $imported++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(($apply ? 'Imported ' : 'Would import ').$imported.' shows.');

        if ($failed) {
            $this->newLine();
            $this->warn(count($failed).' could not be read (re-running will retry them):');

            foreach ($failed as $failure) {
                $this->line('  '.$failure);
            }
        }

        if ($apply) {
            $this->newLine();
            $this->comment('All unpublished and marked pending — none of them have a page.');
        }

        return self::SUCCESS;
    }
}
