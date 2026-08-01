import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

// Control Panel assets build separately from the site (`npm run cp:build`). Statamic 6
// externalises its own Vue/CP runtime, so the CP bundle can't share a Rollup graph with
// the front end — it needs the statamic() plugin and its own manifest under
// public/vendor/app. See Statamic::vite('app', ...) in AppServiceProvider.
export default defineConfig({
    plugins: [
        statamic(),
        laravel({
            input: [
                'resources/css/cp.css',
                'resources/js/cp.js',
            ],
            hotFile: 'public/cp-hot',
            buildDirectory: 'vendor/app',
        }),
    ],
});
