<?php

declare(strict_types=1);

namespace App\Contracts\Services;

use App\Dto\LiveStream;
use Carbon\CarbonInterface;

interface LivePlatformDriver
{
    public function accepts(string $url): bool;

    /**
     * The stream an accepted link points to, worked out from the link alone, live until the given time.
     */
    public function stream(string $url, CarbonInterface $endsAt): LiveStream;
}
