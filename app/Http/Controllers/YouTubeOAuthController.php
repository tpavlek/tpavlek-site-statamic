<?php

namespace App\Http\Controllers;

use Google\Client as GoogleClient;
use Google\Service\YouTube;
use Illuminate\Http\Request;

class YouTubeOAuthController extends Controller
{
    public function handle(Request $request)
    {
        $code = $request->query('code');

        if (! $code) {
            return response('Missing authorization code.', 400);
        }

        $clientId = config('video-distribution.youtube.client_id');
        $clientSecret = config('video-distribution.youtube.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            return response('YOUTUBE_CLIENT_ID and YOUTUBE_CLIENT_SECRET must be set in .env.', 500);
        }

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setScopes([YouTube::YOUTUBE_UPLOAD]);
        $client->setRedirectUri(url('/youtube_oauth_handler'));
        $client->setAccessType('offline');

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return response("Auth failed: {$token['error']} — {$token['error_description']}", 400);
        }

        $refreshToken = $token['refresh_token'] ?? null;

        if (! $refreshToken) {
            return response('No refresh token received. Try revoking app access in your Google account and retrying.', 400);
        }

        return response(
            '<html><body style="font-family:monospace;padding:2rem;">'
            . '<h2>YouTube OAuth Complete</h2>'
            . '<p>Add this to your <code>.env</code> file:</p>'
            . '<pre style="background:#f4f4f4;padding:1rem;border-radius:4px;">YOUTUBE_REFRESH_TOKEN=' . e($refreshToken) . '</pre>'
            . '</body></html>'
        );
    }
}
