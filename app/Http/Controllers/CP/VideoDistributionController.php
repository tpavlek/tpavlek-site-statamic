<?php

namespace App\Http\Controllers\CP;

use App\Http\Controllers\Controller;
use App\Jobs\DistributeVideo;
use App\Models\VideoDistribution;
use App\Services\VideoDistribution\DistributionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;

class VideoDistributionController extends Controller
{
    public function status(string $entryId, DistributionManager $manager): JsonResponse
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $distributions = VideoDistribution::where('entry_id', $entryId)
            ->get()
            ->keyBy('platform');

        $platforms = [];
        foreach ($manager->availablePlatforms() as $name => $platform) {
            $record = $distributions->get($name);
            $platforms[] = [
                'name' => $platform->name(),
                'label' => $platform->label(),
                'field_handle' => $platform->entryFieldHandle(),
                'can_distribute' => $platform->canDistribute($entry),
                'blockers' => $platform->distributionBlockers($entry),
                'status' => $record?->status,
                'platform_id' => $record?->platform_id,
                'error_message' => $record?->error_message,
            ];
        }

        return response()->json(['platforms' => $platforms]);
    }

    public function distribute(string $entryId, Request $request, DistributionManager $manager): JsonResponse
    {
        $request->validate([
            'platform' => 'required|string',
        ]);

        $entry = Entry::find($entryId);

        if (! $entry) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $platformName = $request->input('platform');
        $platform = $manager->resolvePlatform($platformName);

        VideoDistribution::where('entry_id', $entryId)
            ->where('platform', $platformName)
            ->where('status', 'failed')
            ->delete();

        if (! $platform->canDistribute($entry)) {
            return response()->json(['error' => 'Cannot distribute to this platform'], 422);
        }

        VideoDistribution::create([
            'entry_id' => $entryId,
            'platform' => $platformName,
            'status' => 'pending',
        ]);

        DistributeVideo::dispatch($entryId, $platformName);

        return response()->json(['status' => 'pending'], 202);
    }

    public function clear(string $entryId, string $platform, DistributionManager $manager): JsonResponse
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        VideoDistribution::where('entry_id', $entryId)
            ->where('platform', $platform)
            ->delete();

        $platformInstance = $manager->resolvePlatform($platform);
        $fieldHandle = $platformInstance->entryFieldHandle();

        $clearedField = null;
        if ($entry->get($fieldHandle)) {
            $entry->set($fieldHandle, null)->save();
            $clearedField = $fieldHandle;
        }

        return response()->json(['status' => 'cleared', 'cleared_field' => $clearedField]);
    }
}
