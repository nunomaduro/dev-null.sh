<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final readonly class OnlineDevelopersQuery
{
    /**
     * Active window threshold in minutes to consider a developer online.
     */
    public const int ONLINE_WINDOW_MINUTES = 5;

    /**
     * Cache TTL in seconds to throttle database queries.
     */
    public const int CACHE_TTL_SECONDS = 15;

    /**
     * Returns the number of developers active within the online window.
     */
    public function count(): int
    {
        return (int) Cache::remember('online_developers_count', self::CACHE_TTL_SECONDS, function (): int {
            return User::query()
                ->where('last_active_at', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES))
                ->count();
        });
    }
}
