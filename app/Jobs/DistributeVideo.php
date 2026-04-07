<?php

namespace App\Jobs;

use App\Models\VideoDistribution;
use App\Services\VideoDistribution\DistributionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Throwable;

class DistributeVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;
    public int $tries = 2;

    public function __construct(
        public string $entryId,
        public string $platformName,
    ) {}

    public function handle(DistributionManager $manager): void
    {
        $record = VideoDistribution::where('entry_id', $this->entryId)
            ->where('platform', $this->platformName)
            ->firstOrFail();

        $entry = Entry::find($this->entryId);
        $platform = $manager->resolvePlatform($this->platformName);

        $outputPath = config('video-distribution.temp_dir') . '/' . $this->entryId . '-' . $this->platformName . '.mp4';

        try {
            if ($platform->needsTranscoding()) {
                $record->update(['status' => 'transcoding']);
                TranscodeForYoutube::dispatchSync($this->entryId, $outputPath);
                $uploadPath = $outputPath;
            } else {
                $videoAsset = Asset::find('video-sources::' . $entry->get('video_file'));
                $uploadPath = $videoAsset->resolvedPath();
            }

            // Upload
            $record->update(['status' => 'uploading']);
            $platformId = $platform->upload($uploadPath, $entry);

            // Success
            $record->update(['status' => 'success', 'platform_id' => $platformId]);
            $entry->set($platform->entryFieldHandle(), $platformId)->save();
        } finally {
            if ($platform->needsTranscoding()) {
                @unlink($outputPath);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        VideoDistribution::where('entry_id', $this->entryId)
            ->where('platform', $this->platformName)
            ->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
    }
}
