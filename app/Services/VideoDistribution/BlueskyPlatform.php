<?php

namespace App\Services\VideoDistribution;

use App\Contracts\DistributionPlatform;
use App\Models\VideoDistribution;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Statamic\Entries\Entry;

class BlueskyPlatform implements DistributionPlatform
{
    public function name(): string
    {
        return 'bluesky';
    }

    public function label(): string
    {
        return 'Bluesky';
    }

    public function entryFieldHandle(): string
    {
        return 'bluesky';
    }

    public function needsTranscoding(): bool
    {
        return false;
    }

    public function canDistribute(Entry $entry): bool
    {
        return empty($this->distributionBlockers($entry));
    }

    public function distributionBlockers(Entry $entry): array
    {
        $blockers = [];

        if (empty($entry->get('video_file'))) {
            $blockers[] = 'A video file is required.';
        }

        if (empty($entry->get('shortform_content'))) {
            $blockers[] = 'Shortform content is required for Bluesky.';
        }

        if (! empty($entry->get('bluesky'))) {
            $blockers[] = 'Already has a Bluesky post URI.';
        }

        $existing = VideoDistribution::where('entry_id', $entry->id())
            ->where('platform', 'bluesky')
            ->whereIn('status', ['pending', 'uploading', 'success'])
            ->exists();

        if ($existing) {
            $blockers[] = 'A distribution is already in progress or completed.';
        }

        return $blockers;
    }

    public function upload(string $filePath, Entry $entry): string
    {
        $session = $this->createSession();
        $accessJwt = $session['accessJwt'];
        $did = $session['did'];

        // Get a service auth token for the video upload service
        $serviceAuth = Http::withToken($accessJwt)
            ->get('https://bsky.social/xrpc/com.atproto.server.getServiceAuth', [
                'aud' => 'did:web:bsky.social',
                'lxm' => 'com.atproto.repo.uploadBlob',
                'exp' => time() + 1800,
            ]);

        if ($serviceAuth->failed()) {
            throw new RuntimeException('Bluesky service auth failed: ' . $serviceAuth->body());
        }

        $serviceToken = $serviceAuth->json('token');

        // Upload video to the video processing service
        $fileName = basename($filePath);
        $videoData = file_get_contents($filePath);

        $uploadResponse = Http::withToken($serviceToken)
            ->withHeaders([
                'Content-Type' => 'video/mp4',
                'Content-Length' => (string) strlen($videoData),
            ])
            ->withBody($videoData, 'video/mp4')
            ->post("https://video.bsky.app/xrpc/app.bsky.video.uploadVideo?did={$did}&name={$fileName}");

        if ($uploadResponse->failed()) {
            throw new RuntimeException('Bluesky video upload failed: ' . $uploadResponse->body());
        }

        $jobId = $uploadResponse->json('jobId');

        // Poll until processing completes
        $blob = $this->waitForProcessing($serviceToken, $jobId);

        // Create the post with the video embed
        $postResponse = Http::withToken($accessJwt)
            ->post('https://bsky.social/xrpc/com.atproto.repo.createRecord', [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => [
                    '$type' => 'app.bsky.feed.post',
                    'text' => $entry->get('shortform_content'),
                    'createdAt' => now()->toIso8601ZuluString(),
                    'embed' => [
                        '$type' => 'app.bsky.embed.video',
                        'video' => $blob,
                    ],
                ],
            ]);

        if ($postResponse->failed()) {
            throw new RuntimeException('Bluesky post creation failed: ' . $postResponse->body());
        }

        return $postResponse->json('uri');
    }

    private function waitForProcessing(string $serviceToken, string $jobId): array
    {
        $maxAttempts = 60;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = Http::withToken($serviceToken)
                ->get('https://video.bsky.app/xrpc/app.bsky.video.getJobStatus', [
                    'jobId' => $jobId,
                ]);

            if ($status->failed()) {
                throw new RuntimeException('Bluesky video status check failed: ' . $status->body());
            }

            $jobStatus = $status->json('jobStatus');

            if ($jobStatus === 'JOB_STATE_COMPLETED') {
                return $status->json('blob');
            }

            if ($jobStatus === 'JOB_STATE_FAILED') {
                throw new RuntimeException('Bluesky video processing failed');
            }

            sleep(3);
        }

        throw new RuntimeException('Bluesky video processing timed out');
    }

    private function createSession(): array
    {
        $response = Http::post('https://bsky.social/xrpc/com.atproto.server.createSession', [
            'identifier' => config('video-distribution.bluesky.identifier'),
            'password' => config('video-distribution.bluesky.app_password'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Bluesky auth failed: ' . $response->body());
        }

        return $response->json();
    }
}
