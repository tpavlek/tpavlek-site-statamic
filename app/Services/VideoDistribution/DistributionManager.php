<?php

namespace App\Services\VideoDistribution;

use App\Contracts\DistributionPlatform;
use InvalidArgumentException;

class DistributionManager
{
    /** @var array<string, DistributionPlatform> */
    private array $platforms = [];

    public function __construct()
    {
        foreach (config('video-distribution.platforms', []) as $name => $class) {
            $this->platforms[$name] = app($class);
        }
    }

    public function resolvePlatform(string $name): DistributionPlatform
    {
        if (! isset($this->platforms[$name])) {
            throw new InvalidArgumentException("Unknown distribution platform: {$name}");
        }

        return $this->platforms[$name];
    }

    /** @return array<string, DistributionPlatform> */
    public function availablePlatforms(): array
    {
        return $this->platforms;
    }
}
