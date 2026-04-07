<?php

namespace App\Services\VideoDistribution;

use Abraham\TwitterOAuth\TwitterOAuth;
use App\Contracts\DistributionPlatform;
use App\Models\VideoDistribution;
use RuntimeException;
use Statamic\Entries\Entry;

class TwitterPlatform implements DistributionPlatform
{
    public function name(): string
    {
        return 'twitter';
    }

    public function label(): string
    {
        return 'Twitter';
    }

    public function entryFieldHandle(): string
    {
        return 'twitter';
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
            $blockers[] = 'Shortform content is required for Twitter.';
        }

        if (! empty($entry->get('twitter'))) {
            $blockers[] = 'Already has a Twitter ID.';
        }

        $existing = VideoDistribution::where('entry_id', $entry->id())
            ->where('platform', 'twitter')
            ->whereIn('status', ['pending', 'uploading', 'success'])
            ->exists();

        if ($existing) {
            $blockers[] = 'A distribution is already in progress or completed.';
        }

        return $blockers;
    }

    public function upload(string $filePath, Entry $entry): string
    {
        $connection = $this->makeConnection();

        // Chunked video upload via v1.1 media endpoint
        $media = $connection->upload('media/upload', [
            'media' => $filePath,
            'media_type' => 'video/mp4',
            'media_category' => 'tweet_video',
        ]);

        if (! isset($media->media_id_string)) {
            throw new RuntimeException('Twitter media upload failed: ' . json_encode($media));
        }

        // Poll for processing completion
        $this->waitForProcessing($connection, $media->media_id_string);

        // Post the tweet with the video
        $connection->setApiVersion('2');
        $tweet = $connection->post('tweets', [
            'text' => $entry->get('shortform_content'),
            'media' => [
                'media_ids' => [$media->media_id_string],
            ],
        ]);

        if (! isset($tweet->data->id)) {
            throw new RuntimeException('Twitter tweet creation failed: ' . json_encode($tweet));
        }

        return $tweet->data->id;
    }

    private function waitForProcessing(TwitterOAuth $connection, string $mediaId): void
    {
        $connection->setApiVersion('1.1');
        $maxAttempts = 60;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = $connection->mediaStatus($mediaId);

            if (! isset($status->processing_info)) {
                return;
            }

            $state = $status->processing_info->state;

            if ($state === 'succeeded') {
                return;
            }

            if ($state === 'failed') {
                $error = $status->processing_info->error->message ?? 'unknown error';
                throw new RuntimeException("Twitter media processing failed: {$error}");
            }

            $wait = $status->processing_info->check_after_secs ?? 5;
            sleep($wait);
        }

        throw new RuntimeException('Twitter media processing timed out');
    }

    private function makeConnection(): TwitterOAuth
    {
        return new TwitterOAuth(
            config('video-distribution.twitter.consumer_key'),
            config('video-distribution.twitter.consumer_secret'),
            config('video-distribution.twitter.access_token'),
            config('video-distribution.twitter.access_token_secret'),
        );
    }
}
