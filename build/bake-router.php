<?php

/*
 * Router for the PHP built-in server that `php artisan site:bake-static` runs during the
 * build. It makes every request look like it arrived at the live site over HTTPS, so the
 * baked pages carry https://troypavlek.ca canonicals, og:urls and links rather than
 * http://127.0.0.1. The host comes from APP_URL via the command.
 *
 * Only for the bake. Nothing in production routes through this.
 */

$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = getenv('BAKE_HOST');

require __DIR__.'/../public/index.php';
