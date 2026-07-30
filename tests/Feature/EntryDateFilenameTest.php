<?php

namespace Tests\Feature;

use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\User as UserFacade;
use Tests\TestCase;

/**
 * Dated entry files must stay YYYY-MM-DD.slug.md. Saving through the CP hands Entry::date()
 * a Carbon, whose setter branch skips start-of-day normalisation and converts to UTC, so in
 * a non-UTC app timezone the filename picks up a -0600 time suffix.
 *
 * This creates its own entry and deletes it again. The first version of this test PATCHed an
 * existing review instead, and that destroyed content: Statamic's entry update replaces the
 * entry's data with the submitted values, so every field the payload omitted — body, venue,
 * artist, poster, categories, original_review — was stripped from real reviews, silently.
 * Never point a CP create or update request at content the test didn't create.
 */
class EntryDateFilenameTest extends TestCase
{
    private const SLUG = 'date-filename-test';

    private function cleanup(): void
    {
        EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->slug() === self::SLUG)?->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    public function test_saving_through_the_cp_does_not_put_a_time_in_the_filename(): void
    {
        $this->cleanup();

        $created = $this->actingAs(UserFacade::all()->first())->postJson(
            '/cp/collections/fringe_reviews/entries/default',
            [
                'title' => 'Date filename test',
                'slug' => self::SLUG,
                'festival' => ['2026'],
                'date' => '2026-08-15T00:00:00.000Z',
                'recommendation' => 'watchlist',
                'published' => true,
                'blueprint' => 'fringe_review',
                '_blueprint' => 'fringe_review',
            ]
        );

        $created->assertSuccessful();

        $path = basename(EntryFacade::find($created->json('data.id'))->path());

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}\.[^.]+\.md$/',
            $path,
            "Entry file picked up a time component: {$path}"
        );
    }
}
