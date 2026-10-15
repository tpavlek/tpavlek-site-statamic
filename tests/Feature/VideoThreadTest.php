<?php

namespace Tests\Feature;

use App\Support\VideoThread;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * A story told across several videos: each sequel names the one before it in `follows_up`,
 * and every part's page shows the whole chain, the earlier parts lead with an update, and
 * each article ends with a card for the next part.
 *
 * Everything here creates and deletes its own entries rather than editing real content.
 */
class VideoThreadTest extends TestCase
{
    private const SLUGS = ['video-thread-test-one', 'video-thread-test-two', 'video-thread-test-three', 'video-thread-test-alone'];

    private function cleanup(): void
    {
        EntryFacade::query()->where('collection', 'videos')->get()
            ->filter(fn ($e) => in_array($e->slug(), self::SLUGS))
            ->each->delete();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    private function makeVideo(string $slug, string $date, ?string $followsUp = null, bool $article = true)
    {
        $entry = EntryFacade::make()
            ->collection('videos')
            ->slug($slug)
            ->published(true)
            ->data(array_filter([
                'title' => "Thread test {$slug}",
                'youtube' => 'abc123XYZ',
                'publish_date' => $date,
                'content' => "Description of {$slug}.",
                'follows_up' => $followsUp,
                'article' => $article
                    ? [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Article for {$slug}."]]]]
                    : null,
            ]));

        $entry->save();

        return $entry;
    }

    /** @return array{0: \Statamic\Entries\Entry, 1: \Statamic\Entries\Entry, 2: \Statamic\Entries\Entry} */
    private function threeParts(): array
    {
        $one = $this->makeVideo(self::SLUGS[0], '2099-01-01 00:00');
        $two = $this->makeVideo(self::SLUGS[1], '2099-01-02 00:00', $one->id());
        $three = $this->makeVideo(self::SLUGS[2], '2099-01-03 00:00', $two->id(), article: false);

        return [$one, $two, $three];
    }

    public function test_every_part_sees_the_whole_chain_in_order(): void
    {
        [$one, $two, $three] = $this->threeParts();

        foreach ([$one, $two, $three] as $part) {
            $this->assertSame(
                [$one->id(), $two->id(), $three->id()],
                VideoThread::for($part)->map->id()->all(),
            );
        }
    }

    public function test_a_video_outside_a_thread_has_no_chain(): void
    {
        $alone = $this->makeVideo(self::SLUGS[3], '2099-01-01 00:00');

        $this->assertTrue(VideoThread::for($alone)->isEmpty());

        $this->get($alone->url())->assertOk()->assertDontSee('The story so far');
    }

    public function test_an_earlier_part_leads_with_the_latest_and_ends_on_the_next(): void
    {
        [$one, $two, $three] = $this->threeParts();

        $this->get($one->url())
            ->assertOk()
            ->assertSee('The story so far')
            ->assertSeeInOrder(['video-thread__update', $three->url(), 'Article for '.self::SLUGS[0], 'video-thread-next', $two->url()], false);
    }

    public function test_the_latest_part_has_no_update_or_next_card(): void
    {
        [, , $three] = $this->threeParts();

        $this->get($three->url())
            ->assertOk()
            ->assertSee('The story so far')
            ->assertDontSee('video-thread__update', false)
            ->assertDontSee('video-thread-next', false);
    }
}
