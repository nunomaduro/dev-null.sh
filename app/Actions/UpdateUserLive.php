<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LivePlatform;
use App\Models\User;

final readonly class UpdateUserLive
{
    public function handle(User $user, LivePlatform $platform, string $url, int $hours): void
    {
        $endsAt = now()->addHours($hours);

        $user->update([
            'live_platform' => $platform,
            'live_url' => $platform->driver()->stream($url, $endsAt)->url,
            'live_ends_at' => $endsAt,
        ]);
    }
}
