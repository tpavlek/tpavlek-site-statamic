<?php

namespace App\Console\Commands;

use Google\Client as GoogleClient;
use Google\Service\YouTube;
use Illuminate\Console\Command;

class YouTubeAuth extends Command
{
    protected $signature = 'youtube:auth';
    protected $description = 'Complete the one-time YouTube OAuth2 consent flow to obtain a refresh token';

    public function handle(): int
    {
        $clientId = config('video-distribution.youtube.client_id');
        $clientSecret = config('video-distribution.youtube.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            $this->error('Set YOUTUBE_CLIENT_ID and YOUTUBE_CLIENT_SECRET in your .env first.');
            return 1;
        }

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setScopes([YouTube::YOUTUBE_UPLOAD]);
        $redirectUri = $this->ask('Enter your app URL (e.g. https://yourdomain.com)', url('/'));
        $redirectUri = rtrim($redirectUri, '/') . '/youtube_oauth_handler';

        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $authUrl = $client->createAuthUrl();

        $this->info('Open this URL in your browser and authorize the application:');
        $this->newLine();
        $this->line($authUrl);
        $this->newLine();
        $this->info('After authorizing, the refresh token will be displayed in your browser.');

        return 0;
    }
}
