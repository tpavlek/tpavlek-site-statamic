<?php

namespace Tests\Feature;

use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * Posts can link inline to a review with a pin, rendering the same way they do inside a
 * review: title, stars, festival year. The pin config is global to bard_texstyle and the
 * partial is shared, so the only thing that can silently break this is the posts blueprint
 * losing the button or the review_ref entry from its bard field.
 */
class PostPinTest extends TestCase
{
    private function contentField()
    {
        return CollectionFacade::findByHandle('posts')->entryBlueprint()->field('content');
    }

    private function cleanup(): void
    {
        EntryFacade::query()->where('collection', 'posts')->get()
            ->first(fn ($e) => $e->slug() === 'pin-test-post')?->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    public function test_the_posts_bard_field_offers_review_pins(): void
    {
        $field = $this->contentField();

        $this->assertContains('bts_pins', $field->get('buttons'), 'The pins button is missing from the posts editor.');
        $this->assertContains('review_ref', $field->get('bts_pins'), 'review_ref is not among the pins available on posts.');
    }

    /**
     * Shows return under the same name year after year — two "Edmontask", two "Late Night
     * Cabaret" — so the picker has to say which festival each one is from or you can't tell
     * them apart. Statamic renders a fieldtype's item hint as a badge in select mode.
     */
    public function test_the_review_picker_distinguishes_shows_by_festival(): void
    {
        $field = $this->contentField();

        $pin = config('statamic.bard_texstyle.pins.review_ref.fields.review');
        $this->assertSame('review_ref_entries', $pin['type'], 'The pin is not using the festival-aware fieldtype.');

        $fieldtype = new \App\Fieldtypes\ReviewRef;
        $fieldtype->setField(new \Statamic\Fields\Field('review', $pin));

        $sameName = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->filter(fn ($e) => $e->value('title') === 'Edmontask');

        $this->assertGreaterThan(1, $sameName->count(), 'Expected more than one Edmontask review.');

        $hints = $sameName->map(fn ($e) => $fieldtype->getItemHint($e))->values()->all();

        $this->assertSame(count($hints), count(array_unique($hints)), 'Same-titled reviews got the same hint.');
        foreach ($hints as $hint) {
            $this->assertMatchesRegularExpression('/Fringe \d{4}/', (string) $hint);
        }
    }

    public function test_a_pinned_review_renders_in_a_post(): void
    {
        $this->cleanup();

        $review = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->value('title') === '100% Wizard');

        $post = EntryFacade::make()
            ->collection('posts')
            ->slug('pin-test-post')
            ->date('2026-07-30')
            ->data([
                'title' => 'Pin test post',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'The best of the fest was '],
                        ['type' => 'btsPin', 'attrs' => [
                            'id' => 'pintestpost',
                            'values' => ['type' => 'review_ref', 'review' => $review->id()],
                        ]],
                        ['type' => 'text', 'text' => '.'],
                    ],
                ]],
            ]);

        $post->save();

        $response = $this->get($post->url());

        $response->assertOk();
        // Same shape the partial produces inside a review: linked title, stars, year.
        $response->assertSee($review->url(), false);
        $response->assertSee('100% Wizard', false);
        $response->assertSee('★★★★★', false);
        $response->assertSee('(2025)', false);
    }
}
