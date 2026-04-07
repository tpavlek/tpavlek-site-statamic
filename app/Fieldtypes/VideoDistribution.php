<?php

namespace App\Fieldtypes;

use App\Models\VideoDistribution as VideoDistributionModel;
use App\Services\VideoDistribution\DistributionManager;
use Statamic\Fields\Fieldtype;

class VideoDistribution extends Fieldtype
{
    protected static $handle = 'video_distribution';

    public function preload(): array
    {
        $entry = $this->field()->parent();

        if (! $entry instanceof \Statamic\Entries\Entry || ! $entry->id()) {
            return ['entryId' => null, 'platforms' => []];
        }

        $manager = app(DistributionManager::class);
        $distributions = VideoDistributionModel::where('entry_id', $entry->id())
            ->get()
            ->keyBy('platform');

        $platforms = [];
        foreach ($manager->availablePlatforms() as $name => $platform) {
            $record = $distributions->get($name);
            $platforms[] = [
                'name' => $platform->name(),
                'label' => $platform->label(),
                'can_distribute' => $platform->canDistribute($entry),
                'blockers' => $platform->distributionBlockers($entry),
                'status' => $record?->status,
                'platform_id' => $record?->platform_id,
                'error_message' => $record?->error_message,
            ];
        }

        return [
            'entryId' => $entry->id(),
            'platforms' => $platforms,
        ];
    }

    public function process($data)
    {
        return null;
    }

    public function preProcess($data)
    {
        return null;
    }
}
