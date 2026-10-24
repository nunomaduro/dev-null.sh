<?php

declare(strict_types=1);

use App\Jobs\EmbedKnowledgeBase;
use App\Jobs\EndLiveStreams;
use App\Jobs\SyncUpstreamContributions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('commits:submit-scheduled')->everyMinute()->withoutOverlapping(5);
Schedule::job(new EmbedKnowledgeBase())->hourly();
Schedule::job(new SyncUpstreamContributions())->hourly();
Schedule::job(new EndLiveStreams())->everyFiveMinutes();

Schedule::call(function (): void {
    $store = 'cache.stores.'.config()->string('cache.default');

    if (config()->string($store.'.driver') === 'database') {
        /** @var string|null $connection */
        $connection = config($store.'.connection');

        DB::connection($connection)->table(config()->string($store.'.table'))
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();
    }
})->name('prune-expired-cache')->hourly();
