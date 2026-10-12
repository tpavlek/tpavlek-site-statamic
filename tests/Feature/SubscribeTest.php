<?php

namespace Tests\Feature;

use App\Newsletter\Kit;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry as EntryFacade;
use Tests\TestCase;

/**
 * The site's own newsletter signup form, and where it appears.
 *
 * Kit is faked throughout; nothing here touches the real list. Entries are made and
 * deleted by the test itself.
 */
class SubscribeTest extends TestCase
{
    private const SLUG = 'subscribe-test-post';

    protected function setUp(): void
    {
        parent::setUp();

        // Several posts per test would trip the 5-a-minute limit meant for bots.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->app->instance(Kit::class, new Kit('kit_test_key'));
    }

    protected function tearDown(): void
    {
        EntryFacade::query()->where('collection', 'posts')->get()
            ->filter(fn ($e) => $e->slug() === self::SLUG)->each->delete();

        parent::tearDown();
    }

    public function test_a_signup_creates_an_active_subscriber_and_adds_them_to_the_form(): void
    {
        Http::fake(['api.kit.com/*' => Http::response(['subscriber' => ['id' => 1]], 201)]);

        $this->postJson('/subscribe', ['email' => ' Reader@Example.com ', 'referrer' => 'https://troypavlek.ca/posts'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSentInOrder([
            fn (Request $r) => $r->url() === 'https://api.kit.com/v4/subscribers'
                && $r['email_address'] === 'reader@example.com'
                && $r['state'] === 'active',
            fn (Request $r) => $r->url() === 'https://api.kit.com/v4/forms/'.config('services.kit.form_id').'/subscribers'
                && $r['email_address'] === 'reader@example.com'
                && $r['referrer'] === 'https://troypavlek.ca/posts',
        ]);
    }

    public function test_a_bot_filling_the_honeypot_is_told_it_worked_and_nothing_is_sent(): void
    {
        Http::fake();

        $this->postJson('/subscribe', ['email' => 'bot@example.com', 'website' => 'http://spam.example'])
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_invalid_address_is_rejected(): void
    {
        Http::fake();

        $this->postJson('/subscribe', ['email' => 'not an email'])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_kit_failure_is_reported_to_the_reader(): void
    {
        Http::fake(['api.kit.com/*' => Http::response(['errors' => ['nope']], 500)]);

        $this->postJson('/subscribe', ['email' => 'reader@example.com'])
            ->assertStatus(502)
            ->assertJson(['ok' => false]);
    }

    public function test_without_javascript_it_redirects_back_to_the_box(): void
    {
        Http::fake(['api.kit.com/*' => Http::response(['subscriber' => ['id' => 1]], 201)]);

        $this->from('/posts')
            ->post('/subscribe', ['email' => 'reader@example.com'])
            ->assertRedirect(url('/posts').'#subscribe')
            ->assertSessionHas('subscribe_status', 'done');
    }

    private function post_(array $content)
    {
        $entry = EntryFacade::make()->collection('posts')->slug(self::SLUG)->date('2026-07-30')->published(true)
            ->data(['title' => 'Subscribe test', 'content' => $content]);
        $entry->save();

        return $entry;
    }

    public function test_a_post_ends_with_one_subscribe_box(): void
    {
        $post = $this->post_([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Words.']]]]);

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="subscribe"'));
        $this->assertStringNotContainsString('kit.com/665dc00476', $html, 'The Kit embed script is gone.');
    }

    public function test_a_box_placed_in_the_body_replaces_the_one_at_the_end(): void
    {
        $post = $this->post_([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before.']]],
            ['type' => 'set', 'attrs' => ['values' => ['type' => 'email_subscription_prompt']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After the box.']]],
        ]);

        $html = $this->get($post->url())->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="subscribe"'));
        // strrpos: the opening words also turn up earlier, in the head's meta description.
        $this->assertLessThan(strrpos($html, 'After the box.'), strpos($html, 'id="subscribe"'), 'The one box is the mid-article one.');
    }
}
