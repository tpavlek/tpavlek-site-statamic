<?php

namespace Tests\Feature;

use App\Actions\DownloadInstagramCard;
use App\Actions\RegenerateSharingCards;
use App\Og\CardParams;
use App\Og\CardRenderer;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Statamic\Facades\Entry as EntryFacade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * The two sharing-card actions in the CP, which differ in where the file ends up.
 *
 * The link preview is the page's own image, so it is written into the asset container and
 * og_image is pointed at it — a crawler has to be able to fetch it. The Instagram square is
 * uploaded by hand and nothing links to it, so it is rendered to a temp file, streamed to
 * the browser and deleted; a copy in public/assets would ship on every deploy and go stale
 * the moment the description changed.
 *
 * The renderer is mocked throughout: it shells out to headless Chrome, which is too slow and
 * too environment-dependent for a test. What's pinned here is everything around it.
 */
class SharingCardActionsTest extends TestCase
{
    private const SLUG = 'sharing-card-action-test-post';

    private function cleanup(): void
    {
        EntryFacade::query()->where('collection', 'posts')->get()
            ->first(fn ($e) => $e->slug() === self::SLUG)?->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        // The stubbed renders are real files; the action only deletes them once a download
        // has actually been sent, which never happens here.
        foreach (glob(storage_path('app/tmp/instagram-cards/*.png')) ?: [] as $stray) {
            @unlink($stray);
        }

        parent::tearDown();
    }

    private function makePost()
    {
        $this->cleanup();

        $post = EntryFacade::make()
            ->collection('posts')
            ->slug(self::SLUG)
            ->date('2026-07-30')
            ->published(true)
            ->data(['title' => 'Action test', 'og_title' => 'Action test']);

        $post->save();

        return $post;
    }

    private function fakeRenderer(bool $hasChrome = true)
    {
        $renderer = Mockery::mock(CardRenderer::class);
        $renderer->shouldReceive('chrome')->andReturn($hasChrome ? '/fake/chrome' : null);

        $this->app->instance(CardRenderer::class, $renderer);

        return $renderer;
    }

    public static function actionProvider(): array
    {
        return [
            'regenerate' => [RegenerateSharingCards::class],
            'download' => [DownloadInstagramCard::class],
        ];
    }

    #[DataProvider('actionProvider')]
    public function test_it_is_offered_on_posts_and_nowhere_else(string $class): void
    {
        $action = new $class;

        $this->assertTrue($action->visibleTo($this->makePost()));

        $review = EntryFacade::query()->where('collection', 'fringe_reviews')->get()->first();

        $this->assertFalse($action->visibleTo($review));
        $this->assertFalse($action->visibleToBulk(collect()));
    }

    /** A deploy target has no browser. Saying so beats a 500 that reads like a broken feature. */
    #[DataProvider('actionProvider')]
    public function test_it_explains_itself_when_chrome_is_missing(string $class): void
    {
        $post = $this->makePost();
        $renderer = $this->fakeRenderer(hasChrome: false);

        $renderer->shouldNotReceive('render');
        $renderer->shouldNotReceive('renderTo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Headless Chrome is not available');

        (new $class)->run(collect([$post]), []);
    }

    public function test_the_link_preview_is_written_to_assets_and_attached_to_the_entry(): void
    {
        $post = $this->makePost();
        $renderer = $this->fakeRenderer();

        $renderer->shouldReceive('render')
            ->once()
            ->with(['entry' => $post->id()], CardParams::path(self::SLUG))
            ->andReturn('/tmp/whatever.png');

        (new RegenerateSharingCards)->run(collect([$post]), []);

        $this->assertSame(
            CardParams::path(self::SLUG),
            EntryFacade::find($post->id())->value('og_image')
        );
    }

    /**
     * The square never touches the asset container, and never becomes the page's image — a
     * 1:1 crops badly in every link preview there is.
     */
    public function test_the_instagram_card_renders_outside_the_asset_container(): void
    {
        $post = $this->makePost();
        $renderer = $this->fakeRenderer();

        $renderer->shouldNotReceive('render');
        $renderer->shouldReceive('renderTo')
            ->once()
            ->withArgs(function ($params, $destination, $format) use ($post) {
                $this->assertSame(['entry' => $post->id()], $params);
                $this->assertSame('square', $format);
                $this->assertStringStartsWith(storage_path(), $destination);
                $this->assertStringNotContainsString(public_path(), $destination);

                return true;
            })
            ->andReturnUsing(fn ($params, $destination) => $this->stubPng($destination));

        (new DownloadInstagramCard)->run(collect([$post]), []);

        $this->assertNull(EntryFacade::find($post->id())->value('og_image'));
    }

    /** One click, one PNG, named after the post, deleted once it has been sent. */
    public function test_the_instagram_card_comes_back_as_a_download(): void
    {
        $post = $this->makePost();
        $renderer = $this->fakeRenderer();

        $renderer->shouldReceive('renderTo')
            ->once()
            ->andReturnUsing(fn ($params, $destination) => $this->stubPng($destination));

        $action = new DownloadInstagramCard;
        $action->run(collect([$post]), []);

        $response = $action->download(collect([$post]), []);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertStringContainsString(self::SLUG.'-instagram.png', $response->headers->get('content-disposition'));

        @unlink($response->getFile()->getPathname());
    }

    /** Nothing to hand over if the render never happened. */
    public function test_there_is_no_download_without_a_run(): void
    {
        $this->assertFalse((new DownloadInstagramCard)->download(collect(), []));
    }

    private function stubPng(string $destination): string
    {
        @mkdir(dirname($destination), 0755, true);
        file_put_contents($destination, 'not really a png');

        return $destination;
    }
}
