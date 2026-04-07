<?php

namespace App\Services\VideoDistribution;

use App\Contracts\DistributionPlatform;
use App\Models\VideoDistribution;
use Google\Client as GoogleClient;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use RuntimeException;
use Statamic\Entries\Entry;

class YouTubePlatform implements DistributionPlatform
{
    public function name(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function entryFieldHandle(): string
    {
        return 'youtube';
    }

    public function needsTranscoding(): bool
    {
        return true;
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

        if (empty($entry->get('thumbnail'))) {
            $blockers[] = 'A thumbnail is required.';
        }

        if (! empty($entry->get('youtube'))) {
            $blockers[] = 'Already has a YouTube ID.';
        }

        $existing = VideoDistribution::where('entry_id', $entry->id())
            ->where('platform', 'youtube')
            ->whereIn('status', ['pending', 'transcoding', 'uploading', 'success'])
            ->exists();

        if ($existing) {
            $blockers[] = 'A distribution is already in progress or completed.';
        }

        return $blockers;
    }

    public function upload(string $filePath, Entry $entry): string
    {
        $client = $this->makeClient();
        $youtube = new YouTube($client);

        $snippet = new VideoSnippet();
        $snippet->setTitle($entry->get('title'));
        $snippet->setDescription(strip_tags($entry->value('content') ?? ''));
        $snippet->setCategoryId('22'); // People & Blogs

        $status = new VideoStatus();
        $status->setPrivacyStatus('private'); // Upload as private, user publishes manually

        $video = new Video();
        $video->setSnippet($snippet);
        $video->setStatus($status);

        $client->setDefer(true);

        $request = $youtube->videos->insert('snippet,status', $video);

        $chunkSize = 2 * 1024 * 1024; // 2MB chunks
        $media = new \Google\Http\MediaFileUpload(
            $client,
            $request,
            'video/*',
            null,
            true, // resumable
            $chunkSize,
        );

        $fileSize = filesize($filePath);
        $media->setFileSize($fileSize);

        $handle = fopen($filePath, 'rb');
        $response = false;

        while (! $response && ! feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            $response = $media->nextChunk($chunk);
        }

        fclose($handle);
        $client->setDefer(false);

        if (! $response instanceof Video) {
            throw new RuntimeException('YouTube upload failed: no response received');
        }

        return $response->getId();
    }

    private function makeClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('video-distribution.youtube.client_id'));
        $client->setClientSecret(config('video-distribution.youtube.client_secret'));
        $client->setScopes([YouTube::YOUTUBE_UPLOAD]);

        $client->fetchAccessTokenWithRefreshToken(config('video-distribution.youtube.refresh_token'));

        return $client;
    }
}
