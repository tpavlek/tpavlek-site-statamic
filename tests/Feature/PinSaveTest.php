<?php

namespace Tests\Feature;

use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\User as UserFacade;
use Tests\TestCase;

/**
 * A "pin" (bard_texstyle) added to a review's body must survive the save whether the entry is
 * being created or updated. Adding one while creating a new review silently dropped it.
 */
class PinSaveTest extends TestCase
{
    private function user()
    {
        return UserFacade::all()->first();
    }

    private function target(): string
    {
        return EntryFacade::query()->where('collection', 'fringe_reviews')->get()->first()->id();
    }

    private function bardWithPin(string $targetId): array
    {
        return [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Compare with '],
                    [
                        'type' => 'btsPin',
                        'attrs' => [
                            'id' => 'pintest1',
                            'values' => ['type' => 'review_ref', 'review' => [$targetId]],
                        ],
                    ],
                    ['type' => 'text', 'text' => ' for context.'],
                ],
            ],
        ];
    }

    /** A pin sitting alone in a paragraph, with no surrounding text. */
    private function bardWithBarePin(string $targetId): array
    {
        return [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'btsPin',
                        'attrs' => [
                            'id' => 'pintest2',
                            'values' => ['type' => 'review_ref', 'review' => [$targetId]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** A pin whose review hasn't been chosen yet, i.e. the defaults the menu inserts. */
    private function bardWithUnfilledPin(): array
    {
        return [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'See '],
                    [
                        'type' => 'btsPin',
                        'attrs' => [
                            'id' => 'pintest3',
                            'values' => ['type' => 'review_ref', 'review' => []],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function payload(string $title, array $bard): array
    {
        return [
            'title' => $title,
            'content' => $bard,
            'festival' => ['2026'],
            'date' => '2026-08-15T00:00:00.000Z',
            'recommendation' => 'watchlist',
            'slug' => 'pin-save-test',
            'published' => true,
            'blueprint' => 'fringe_review',
            '_blueprint' => 'fringe_review',
        ];
    }

    private function pinNode($entry): ?array
    {
        foreach ($entry->value('content') ?? [] as $node) {
            foreach ($node['content'] ?? [] as $child) {
                if (($child['type'] ?? null) === 'btsPin') {
                    return $child;
                }
            }
        }

        return null;
    }

    private function cleanup(): void
    {
        $existing = EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->slug() === 'pin-save-test');

        $existing?->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    public function test_pin_survives_creating_a_new_entry(): void
    {
        $this->cleanup();
        $target = $this->target();

        $response = $this
            ->actingAs($this->user())
            ->postJson(
                '/cp/collections/fringe_reviews/entries/default',
                $this->payload('Pin Save Test', $this->bardWithPin($target))
            );

        $response->assertSuccessful();

        $entry = EntryFacade::find($response->json('data.id'));
        $pin = $this->pinNode($entry);

        $this->assertNotNull($pin, 'The pin node was dropped when creating the entry.');
        $this->assertSame('review_ref', $pin['attrs']['values']['type']);

        $review = $pin['attrs']['values']['review'];
        $this->assertSame($target, is_array($review) ? ($review[0] ?? null) : $review);
    }

    public function test_bare_pin_survives_creating_a_new_entry(): void
    {
        $this->cleanup();
        $target = $this->target();

        $response = $this
            ->actingAs($this->user())
            ->postJson(
                '/cp/collections/fringe_reviews/entries/default',
                $this->payload('Pin Save Test', $this->bardWithBarePin($target))
            );

        $response->assertSuccessful();

        $pin = $this->pinNode(EntryFacade::find($response->json('data.id')));

        $this->assertNotNull($pin, 'A pin alone in a paragraph was dropped when creating the entry.');
    }

    public function test_unfilled_pin_survives_creating_a_new_entry(): void
    {
        $this->cleanup();

        $response = $this
            ->actingAs($this->user())
            ->postJson(
                '/cp/collections/fringe_reviews/entries/default',
                $this->payload('Pin Save Test', $this->bardWithUnfilledPin())
            );

        $response->assertSuccessful();

        $pin = $this->pinNode(EntryFacade::find($response->json('data.id')));

        $this->assertNotNull($pin, 'A pin with no review chosen was dropped when creating the entry.');
    }

    public function test_pin_survives_updating_an_existing_entry(): void
    {
        $this->cleanup();
        $target = $this->target();

        $created = $this
            ->actingAs($this->user())
            ->postJson(
                '/cp/collections/fringe_reviews/entries/default',
                $this->payload('Pin Save Test', [])
            );

        $created->assertSuccessful();
        $id = $created->json('data.id');

        $response = $this
            ->actingAs($this->user())
            ->patchJson(
                "/cp/collections/fringe_reviews/entries/{$id}",
                $this->payload('Pin Save Test', $this->bardWithPin($target))
            );

        $response->assertSuccessful();

        $pin = $this->pinNode(EntryFacade::find($id)->fresh());

        $this->assertNotNull($pin, 'The pin node was dropped when updating the entry.');
        $this->assertSame('review_ref', $pin['attrs']['values']['type']);
    }
}

function Arr($value)
{
    return is_array($value) ? ($value[0] ?? null) : $value;
}
