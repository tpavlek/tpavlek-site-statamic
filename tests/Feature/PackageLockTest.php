<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Optional packages in package-lock.json must keep their own dependencies, or the deploy dies.
 *
 * The trap, hit on 2026-08-05: running `npm install` on macOS silently dropped
 * `@emnapi/core` and `@emnapi/runtime` from the lock. They're dependencies of
 * `@rolldown/binding-wasm32-wasi` — an optional, platform-specific package that never
 * installs on a Mac — so npm pruned entries it decided this machine would never need. The
 * Linux deploy still needs them described, and `npm ci` refuses a lock it considers out of
 * sync with package.json.
 *
 * What makes it worth a test is how quiet it is: `npm install`, `npm run build` and
 * `npm run cp:build` all keep working locally, the lock diff looks like a normal dependency
 * bump, and the failure surfaces days later in a deploy log that blames package.json.
 * Any future `npm install` can retrigger it.
 *
 * Scoped to optional packages deliberately. A general "every dependency has an entry" check
 * fails all over this lock, because `@statamic/cms` is a `file:` dependency pointing into
 * vendor/ and the root lock genuinely doesn't describe its subtree. Optional packages are
 * both where the pruning happens and where the lock is expected to be complete.
 *
 * If this fails, restore the missing entries from a known-good lock in git history — do not
 * just re-run `npm install`, which prunes them again.
 */
class PackageLockTest extends TestCase
{
    public function test_optional_packages_keep_their_dependencies(): void
    {
        $path = base_path('package-lock.json');

        $this->assertFileExists($path);

        $packages = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['packages'] ?? [];

        $this->assertNotEmpty($packages, 'package-lock.json has no packages.');

        $missing = [];

        foreach ($packages as $name => $package) {
            if (! ($package['optional'] ?? false)) {
                continue;
            }

            $declared = array_merge(
                array_keys($package['dependencies'] ?? []),
                array_keys($package['optionalDependencies'] ?? []),
            );

            foreach ($declared as $dependency) {
                if (! isset($packages['node_modules/'.$dependency])) {
                    $missing[] = "{$name} declares {$dependency}, which has no entry";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'package-lock.json is missing entries that `npm ci` needs, so the deploy will',
            'fail even though `npm install` and `npm run build` work here.',
            '',
            '  '.implode("\n  ", $missing),
            '',
            'Restore them from a known-good lock in git history. Re-running `npm install`',
            'will prune them again — that is what caused this.',
        ]));
    }
}
