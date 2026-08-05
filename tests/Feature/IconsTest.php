<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Icons are SVG masks generated from the Font Awesome sources by build/icons.mjs, which
 * emits only the ones the site uses — see that file for why the kit script had to go.
 *
 * The failure mode of a curated list is silence: reference an icon that isn't in it and the
 * <i> renders as an empty 1em box. Nothing errors, no page breaks, the icon is just gone,
 * and you find out when someone mentions the venue marker disappeared. So this walks every
 * icon class in the templates and in content and checks the stylesheet actually has it.
 */
class IconsTest extends TestCase
{
    /**
     * The family classes carry the shared box, not a glyph, so they're never icon names.
     */
    private const FAMILIES = ['fa-solid', 'fa-brands', 'fa-regular'];

    private function iconClassesInUse(): array
    {
        $found = [];

        foreach ([resource_path('views'), base_path('content')] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['html', 'md', 'yaml'], true)) {
                    continue;
                }

                // Only inside a class attribute or a bare `icon:` value. A loose `fa-` scan
                // matches prose and CSS utility names alike.
                preg_match_all(
                    '~(?:class="[^"]*|icon:\s*["\']?)\b(fa-[a-z0-9-]+)~i',
                    (string) file_get_contents($file->getPathname()),
                    $matches,
                );

                foreach ($matches[1] as $class) {
                    if (! in_array($class, self::FAMILIES, true)) {
                        $found[strtolower($class)] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    public function test_every_icon_the_site_uses_is_generated(): void
    {
        $css = file_get_contents(resource_path('css/icons.css'));

        $this->assertNotEmpty($css, 'icons.css is missing — run `npm run icons`.');

        $missing = array_values(array_filter(
            $this->iconClassesInUse(),
            fn (string $class) => ! str_contains($css, ".{$class}{"),
        ));

        $this->assertSame([], $missing, implode("\n", [
            'These icons are used but not generated, so they render as empty boxes.',
            'Add them to ICONS in build/icons.mjs and run `npm run icons`:',
            '  '.implode(', ', $missing),
        ]));
    }

    /**
     * The kit was the largest render-blocking request on every page. Nothing should quietly
     * reintroduce it — an icon that seems missing is exactly the itch that would.
     */
    public function test_no_page_loads_font_awesome_from_a_cdn(): void
    {
        foreach (['/', '/fringe', '/fringe/reviews'] as $url) {
            $this->assertStringNotContainsString(
                'fontawesome.com',
                $this->get($url)->assertOk()->getContent(),
                "{$url} is loading Font Awesome from a CDN again.",
            );
        }
    }
}
