<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\User as UserFacade;
use Tests\TestCase;

class OgImageTest extends TestCase
{
    private function review()
    {
        return EntryFacade::query()->where('collection', 'fringe_reviews')->get()
            ->first(fn ($e) => $e->slug() === '2026-field-zoology-301');
    }

    public function test_admin_sees_og_tab_and_anonymous_does_not(): void
    {
        $entry = $this->review();

        $admin = $this->actingAs(UserFacade::all()->first())
            ->get(cp_route('fringe-social-card.show', $entry->id()));
        $admin->assertOk();
        $c = $admin->getContent();
        foreach (['OpenGraph', 'Set as OG image', 'setOgImage', 'Save to entry'] as $needle) {
            $this->assertStringContainsString($needle, $c, "admin page missing: {$needle}");
        }

    }

    /**
     * Separate test so the request is genuinely anonymous — actingAs() persists for the
     * whole test method, and a signed-in admin viewing the public URL is still an admin.
     */
    public function test_anonymous_visitors_get_no_og_controls(): void
    {
        $entry = $this->review();

        $public = $this->get($entry->url().'/share-card');
        $public->assertOk();
        $html = $public->getContent();

        foreach (['Set as OG image', 'setOgImage', 'og-image', 'Save to entry', 'ogMessage'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "leaked to anonymous visitors: {$needle}");
        }
    }

    public function test_it_uploads_and_sets_the_og_image(): void
    {
        $entry = $this->review();
        $before = $entry->value('og_image');

        $png = UploadedFile::fake()->image('card.png', 1200, 630);

        $response = $this->actingAs(UserFacade::all()->first())
            ->post(cp_route('fringe-social-card.og', $entry->id()), ['image' => $png]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'path']);

        $fresh = EntryFacade::find($entry->id());
        $path = $fresh->value('og_image');
        $this->assertSame('fringe/og/2026-field-zoology-301.png', $path);
        $this->assertNotNull(AssetContainer::findByHandle('assets')->asset($path), 'asset missing');

        // leave the entry exactly as it was found
        AssetContainer::findByHandle('assets')->asset($path)?->delete();
        $before === null ? $fresh->remove('og_image')->save() : $fresh->set('og_image', $before)->save();
    }
}
