<?php

namespace App\Contracts;

use Statamic\Entries\Entry;

interface DistributionPlatform
{
    public function name(): string;

    public function label(): string;

    public function entryFieldHandle(): string;

    public function needsTranscoding(): bool;

    public function canDistribute(Entry $entry): bool;

    /**
     * Returns an array of human-readable reasons why distribution is currently blocked.
     * An empty array means distribution is allowed.
     *
     * @return string[]
     */
    public function distributionBlockers(Entry $entry): array;

    /**
     * Upload the video file to the platform.
     *
     * @return string The platform-specific ID (e.g., YouTube video ID)
     */
    public function upload(string $filePath, Entry $entry): string;
}
