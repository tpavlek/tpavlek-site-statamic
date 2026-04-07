<?php

namespace App\Services\VideoDistribution;

use App\Contracts\DistributionPlatform;
use App\Models\VideoDistribution;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Statamic\Entries\Entry;

class TikTokPlatform implements DistributionPlatform
{
    public function name(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    public function entryFieldHandle(): string
    {
        return 'tiktok';
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

        if (! empty($entry->get('tiktok'))) {
            $blockers[] = 'Already has a TikTok ID.';
        }

        $existing = VideoDistribution::where('entry_id', $entry->id())
            ->where('platform', 'tiktok')
            ->whereIn('status', ['pending', 'uploading', 'success'])
            ->exists();

        if ($existing) {
            $blockers[] = 'A distribution is already in progress or completed.';
        }

        return $blockers;
    }

    public function upload(string $filePath, Entry $entry): string
    {
        $accessToken = $this->getAccessToken();
        $fileSize = filesize($filePath);
        $chunkSize = min(64 * 1024 * 1024, max(5 * 1024 * 1024, $fileSize)); // 5-64MB
        $totalChunks = (int) ceil($fileSize / $chunkSize);

        // Initialize the upload
        $initResponse = Http::withToken($accessToken)
            ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                'post_info' => [
                    'title' => mb_substr($entry->get('title') ?? '', 0, 2200),
                    'privacy_level' => 'SELF_ONLY',
                ],
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => $fileSize,
                    'chunk_size' => $chunkSize,
                    'total_chunk_count' => $totalChunks,
                ],
            ]);

        if ($initResponse->failed() || $initResponse->json('error.code') !== 'ok') {
            throw new RuntimeException(
                'TikTok upload init failed: ' . ($initResponse->json('error.message') ?? $initResponse->body())
            );
        }

        $uploadUrl = $initResponse->json('data.upload_url');
        $publishId = $initResponse->json('data.publish_id');

        // Upload file in chunks
        $handle = fopen($filePath, 'rb');
        $offset = 0;

        while ($offset < $fileSize) {
            $currentChunkSize = min($chunkSize, $fileSize - $offset);
            $chunk = fread($handle, $currentChunkSize);
            $lastByte = $offset + $currentChunkSize - 1;

            $chunkResponse = Http::withHeaders([
                'Content-Type' => 'video/mp4',
                'Content-Length' => (string) $currentChunkSize,
                'Content-Range' => "bytes {$offset}-{$lastByte}/{$fileSize}",
            ])->withBody($chunk, 'video/mp4')->put($uploadUrl);

            if (! in_array($chunkResponse->status(), [201, 206])) {
                fclose($handle);
                throw new RuntimeException(
                    'TikTok chunk upload failed (status ' . $chunkResponse->status() . '): ' . $chunkResponse->body()
                );
            }

            $offset += $currentChunkSize;
        }

        fclose($handle);

        return $publishId;
    }

    private function getAccessToken(): string
    {
        $response = Http::asForm()->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => config('video-distribution.tiktok.client_key'),
            'client_secret' => config('video-distribution.tiktok.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => config('video-distribution.tiktok.refresh_token'),
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('TikTok auth failed: ' . $response->body());
        }

        return $response->json('access_token');
    }
}
