<?php

return [
    'ffmpeg_path' => env('FFMPEG_PATH', '/opt/homebrew/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/opt/homebrew/bin/ffprobe'),
    'temp_dir' => storage_path('app/private/video-distribution'),

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'refresh_token' => env('YOUTUBE_REFRESH_TOKEN'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'refresh_token' => env('TIKTOK_REFRESH_TOKEN'),
    ],

    'twitter' => [
        'consumer_key' => env('TWITTER_CONSUMER_KEY'),
        'consumer_secret' => env('TWITTER_CONSUMER_SECRET'),
        'access_token' => env('TWITTER_ACCESS_TOKEN'),
        'access_token_secret' => env('TWITTER_ACCESS_TOKEN_SECRET'),
    ],

    'bluesky' => [
        'identifier' => env('BLUESKY_IDENTIFIER'),
        'app_password' => env('BLUESKY_APP_PASSWORD'),
    ],

    'platforms' => [
        'youtube' => \App\Services\VideoDistribution\YouTubePlatform::class,
        'tiktok' => \App\Services\VideoDistribution\TikTokPlatform::class,
        'twitter' => \App\Services\VideoDistribution\TwitterPlatform::class,
        'bluesky' => \App\Services\VideoDistribution\BlueskyPlatform::class,
    ],
];
