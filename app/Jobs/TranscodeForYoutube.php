<?php

namespace App\Jobs;

use App\Services\VideoDistribution\VideoTranscoder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Statamic\Facades\Entry;

class TranscodeForYoutube implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public function __construct(
        public string $entryId,
        public string $outputPath,
    ) {}

    public function handle(): void
    {
        if (file_exists($this->outputPath)) {
            return;
        }

        $entry = Entry::find($this->entryId);

        $outputDir = dirname($this->outputPath);
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $transcoder = app(VideoTranscoder::class);
        $transcoder->transcode($entry, $this->outputPath);
    }
}
