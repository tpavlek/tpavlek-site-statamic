<?php

namespace App\Services\VideoDistribution;

use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset;
use Symfony\Component\Process\Process;

class VideoTranscoder
{
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct()
    {
        $this->ffmpegPath = config('video-distribution.ffmpeg_path');
        $this->ffprobePath = config('video-distribution.ffprobe_path');
    }

    public function transcode(Entry $entry, string $outputPath): void
    {
        $videoAsset = Asset::find('video-sources::' . $entry->get('video_file'));
        if (! $videoAsset) {
            throw new RuntimeException("Video asset not found: video-sources::{$entry->get('video_file')}");
        }

        $thumbnailAsset = Asset::find('assets::' . $entry->get('thumbnail'));
        if (! $thumbnailAsset) {
            throw new RuntimeException("Thumbnail asset not found: assets::{$entry->get('thumbnail')}");
        }

        $sourceVideoPath = $videoAsset->resolvedPath();
        $thumbnailPath = $thumbnailAsset->resolvedPath();

        $fps = $this->probeFramerate($sourceVideoPath);
        $thumbnailDuration = 3 / $fps;

        $process = new Process([
            $this->ffmpegPath, '-y',
            '-loop', '1',
            '-t', (string) $thumbnailDuration,
            '-i', $thumbnailPath,
            '-i', $sourceVideoPath,
            '-filter_complex',
            "[0:v]fps={$fps},format=yuv420p[v0];" .
            "[1:v]setsar=1,format=yuv420p[v1];" .
            "anullsrc=channel_layout=stereo:sample_rate=48000,atrim=duration={$thumbnailDuration}[a0];" .
            "[1:a]aresample=48000[a1];" .
            "[v0][a0][v1][a1]concat=n=2:v=1:a=1[vout][aout]",
            '-map', '[vout]',
            '-map', '[aout]',
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '18',
            '-c:a', 'aac',
            '-movflags', '+faststart',
            $outputPath,
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('FFmpeg transcoding failed: ' . $process->getErrorOutput());
        }
    }

    private function probeFramerate(string $videoPath): float
    {
        $process = new Process([
            $this->ffprobePath,
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=r_frame_rate',
            '-of', 'csv=p=0',
            $videoPath,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('FFprobe failed: ' . $process->getErrorOutput());
        }

        $output = trim($process->getOutput());

        // r_frame_rate returns as a fraction like "30/1" or "30000/1001"
        if (str_contains($output, '/')) {
            [$num, $den] = explode('/', $output);
            return (float) $num / (float) $den;
        }

        return (float) $output;
    }
}
