import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// Front-end assets only. The Control Panel builds from vite-cp.config.js.
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/site.css',
                'resources/js/site.js',
            ],
            refresh: true,
        }),
    ],
});
