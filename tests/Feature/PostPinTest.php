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
