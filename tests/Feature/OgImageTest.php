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

    /**
     * The upload writes to a deterministic path derived from the entry slug, and the
     * "assets" disk is rooted at public/assets — the real, committed files. So this test
     * overwrites a tracked asset every time it runs.
     *
     * The teardown used to just delete the uploaded file, which meant a committed asset
     * disappeared from the working tree on every test run, and any failed assertion left
     * the overwrite in place. Snapshot the bytes first and restore them in a finally
     * instead. Isolating the disk would be tidier, but public/assets is ~300MB and the
     * other tests in this file render pages that read from it.
     */
    public function test_it_uploads_and_sets_the_og_image(): void
    {
        $entry = $this->review();
        $before = $entry->value('og_image');

        $path = 'fringe/og/2026-field-zoology-301.png';
        $snapshot = $this->snapshot($path);

        try {
            $png = UploadedFile::fake()->image('card.png', 1200, 630);

            $response = $this->actingAs(UserFacade::all()->first())
                ->post(cp_route('fringe-social-card.og', $entry->id()), ['image' => $png]);

            $response->assertOk();
            $response->assertJsonStructure(['message', 'path']);

            $fresh = EntryFacade::find($entry->id());
            $this->assertSame($path, $fresh->value('og_image'));
            $this->assertNotNull(AssetContainer::findByHandle('assets')->asset($path), 'asset missing');
        } finally {
            $this->restore($path, $snapshot);

            $fresh = EntryFacade::find($entry->id());
            $before === null
                ? $fresh->remove('og_image')->save()
                : $fresh->set('og_image', $before)->save();
        }
    }

    /**
     * The asset file and its Statamic .meta sidecar, or null for either if absent.
     */
    private function snapshot(string $path): array
    {
        return [
            'asset' => is_file($this->assetPath($path)) ? file_get_contents($this->assetPath($path)) : null,
            'meta' => is_file($this->metaPath($path)) ? file_get_contents($this->metaPath($path)) : null,
        ];
    }

    /**
     * Put the bytes back, then re-sync the container's cached file listing.
     *
     * That second step is not optional. The listing lives in the Laravel cache, not the
     * Stache, so `please stache:clear` does not touch it, and writing files underneath it
     * leaves the container insisting the asset does not exist. That in turn breaks the
     * *next* run: SocialCardController deletes any existing card before uploading, the
     * delete silently no-ops on an asset the container can't see, and the upload then
     * de-duplicates itself to `name-{timestamp}.png` while the entry still points at
     * `name.png`. Diagnosed the hard way.
     */
    private function restore(string $path, array $snapshot): void
    {
        foreach (['asset' => $this->assetPath($path), 'meta' => $this->metaPath($path)] as $key => $file) {
            if ($snapshot[$key] === null) {
                // Nothing was there before, so the test created it. Take it back out.
                is_file($file) && unlink($file);

                continue;
            }

            is_dir(dirname($file)) || mkdir(dirname($file), 0755, true);
            file_put_contents($file, $snapshot[$key]);
        }

        $contents = AssetContainer::findByHandle('assets')->contents();

        $snapshot['asset'] === null ? $contents->forget($path) : $contents->add($path);

        $contents->save();
    }

    private function assetPath(string $path): string
    {
        return public_path('assets/'.$path);
    }

    private function metaPath(string $path): string
    {
        return public_path('assets/'.dirname($path).'/.meta/'.basename($path).'.yaml');
    }
}
