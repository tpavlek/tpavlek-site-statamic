<?php

namespace App\Console\Commands;

use App\Fringe\Artists;
use App\Fringe\FestivalUrls;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Collection;
use Statamic\Console\Commands\StaticWarm;
use Statamic\Facades\Term;
use Statamic\StaticCaching\Cacher;
use Symfony\Component\Process\Process;

/**
 * Pre-renders every page into the full static cache (public/static) at build time, so the
 * deployed image ships with the whole site already rendered and nginx serves it without
 * touching PHP.
 *
 * Why it matters: a page render peaks at 60–100MB of PHP memory, and Laravel Cloud sizes the
 * FPM pool assuming 30MB per worker. A crawler walking uncached pages fills every worker at
 * once and the instance runs out of memory (2026-10-29). Pages already on disk never reach
 * a worker.
 *
 * Statamic's own static:warm makes HTTP requests to APP_URL, which during a Cloud build is
 * the *previous* deploy. This walks the same URL list (entries, taxonomies, Route::statamic
 * pages, plus the controller routes below) but requests each one from a PHP built-in server
 * on localhost, started for the purpose, and the static cache middleware writes the file as
 * it would for a visitor. Over HTTP rather than in-process on purpose: Statamic keeps
 * per-request state in singletons (the view cascade is bound to the first request it sees),
 * so rendering many pages in one process gives every page the first one's canonical URL.
 *
 * No invalidation to speak of: content only changes by deploy, and every deploy starts from a
 * fresh filesystem and bakes again.
 */
class StaticBake extends StaticWarm
{
    protected $signature = 'site:bake-static
        {--workers=4 : Local servers to run, one request each at a time}';

    protected $description = 'Render every page into the full static cache from a local server (for the build)';

    public function handle()
    {
        if (config('statamic.static_caching.strategy') !== 'full') {
            $this->components->warn('Static caching strategy is not "full"; nothing to bake.');

            return self::SUCCESS;
        }

        $cacher = app(Cacher::class);
        $cacher->flush();

        $uris = $this->bakeableUris($cacher);
        $origin = rtrim(config('app.url'), '/');
        $workers = (int) $this->option('workers');

        // Separate single-process servers rather than one with PHP_CLI_SERVER_WORKERS: stopping
        // that one kills only its parent, and the forked workers keep the build from finishing.
        $servers = collect(range(1, $workers))
            ->mapWithKeys(fn () => $this->startServer(parse_url($origin, PHP_URL_HOST)));
        $ports = $servers->keys();

        $this->components->info("Baking {$uris->count()} pages");

        $failed = [];
        $started = microtime(true);
        $client = new Client(['http_errors' => false, 'timeout' => 60]);

        $requests = $uris->map(fn ($uri, $i) => new Request(
            'GET',
            'http://127.0.0.1:'.$ports[$i % $workers].(parse_url($uri, PHP_URL_PATH) ?: '/'),
        ));

        (new Pool($client, $requests->all(), [
            'concurrency' => $workers,
            'fulfilled' => function (Response $response, $i) use ($uris, $cacher, &$failed) {
                if ($response->getStatusCode() !== 200) {
                    $failed[] = "{$response->getStatusCode()} {$uris[$i]}";
                } elseif (! $cacher->hasCachedPage(HttpRequest::create($uris[$i]))) {
                    $failed[] = "not written {$uris[$i]}: ".substr(strip_tags((string) $response->getBody()), 0, 200);
                }
            },
            'rejected' => function ($reason, $i) use ($uris, &$failed) {
                $failed[] = "error {$uris[$i]}: ".$reason->getMessage();
            },
        ]))->promise()->wait();

        $servers->each->stop();

        $baked = $uris->count() - count($failed);

        $this->components->info(sprintf('Baked %d pages in %ds', $baked, microtime(true) - $started));

        // Not a build failure: a page that 404s or redirects is simply left to PHP, the same
        // as before this existed.
        foreach ($failed as $line) {
            $this->components->warn("Not cached: {$line}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, Process> the server, keyed by its port
     */
    private function startServer(string $host): array
    {
        // A port the OS says is free right now. A fixed one can already belong to some other
        // local server, which then answers the health check and a share of the bake.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $server = new Process(
            [PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'display_errors=stderr', '-S', "127.0.0.1:{$port}", '-t', public_path(), base_path('build/bake-router.php')],
            base_path(),
            ['BAKE_HOST' => $host],
        );
        $server->setTimeout(null);
        // The server logs every request; captured and never read, that fills the pipe and
        // the server blocks mid-bake.
        $server->disableOutput();
        $server->start();

        // /up is Laravel's health route: answering it means the app boots.
        for ($i = 0; $i < 100; $i++) {
            if (! $server->isRunning()) {
                throw new \RuntimeException("Bake server exited with code {$server->getExitCode()}");
            }

            if (@file_get_contents("http://127.0.0.1:{$port}/up") !== false) {
                return [$port => $server];
            }

            usleep(100_000);
        }

        $server->stop();

        throw new \RuntimeException("Bake server didn't answer on port {$port}");
    }

    private function bakeableUris(Cacher $cacher): Collection
    {
        return collect()
            ->merge($this->entryUris())
            ->merge($this->taxonomyUris())
            ->merge($this->termUris())
            ->merge($this->customRouteUris())
            ->merge($this->controllerUris())
            ->unique()
            ->reject(fn ($uri) => $cacher->isExcluded($uri))
            ->sort()
            ->values();
    }

    /**
     * The controller routes that carry the StaticCache middleware in routes/web.php. Keep the
     * two in step: a route listed here without the middleware renders and caches nothing.
     */
    private function controllerUris(): Collection
    {
        $years = Term::whereTaxonomy('fringe_festival')
            ->map->slug()
            ->reject(fn ($year) => FestivalUrls::isCurrent($year))
            ->map(fn ($year) => "/fringe/{$year}/reviews");

        return collect([
            '/fringe/artists',
            '/yegvote-2025',
            '/yegvote-2025/endorsements',
        ])
            ->merge($years)
            ->merge(Artists::withPages()->map(fn ($artist) => Artists::url($artist)))
            ->map(fn ($path) => FestivalUrls::absolute($path));
    }
}
