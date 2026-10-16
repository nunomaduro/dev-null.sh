<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\CommitImpression;
use App\Models\CommitImpressionBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\ConcurrentImpressionBatches;
use Tests\Support\ImpressionEvents;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

beforeEach(function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Independent PostgreSQL connection tests require pcntl and posix.');
    }
});

it('counts simultaneous first and repeat exposures once per signed-in viewer', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $racer = new ConcurrentImpressionBatches;
    $batch = fn (): array => ['user' => $user, 'batch_id' => (string) Str::uuid(), 'impressions' => ImpressionEvents::for($user, [$commit])];

    $racer->run([$batch(), $batch()]);

    expect($commit->fresh()->impressions_count)->toBeOne()
        ->and(CommitImpression::query()->count())->toBeOne()
        ->and(CommitImpressionBatch::query()->count())->toBe(2);

    CommitImpression::query()->update(['last_counted_at' => now()->subMinutes(31)]);
    $racer->run([$batch(), $batch()]);

    expect($commit->fresh()->impressions_count)->toBe(2)
        ->and(CommitImpression::query()->count())->toBeOne()
        ->and(CommitImpressionBatch::query()->count())->toBe(4);
});

it('counts overlapping reverse-order batches from different viewers without deadlocks or lost increments', function (): void {
    $users = User::factory()->count(2)->create();
    $commits = Commit::factory()->count(2)->create();

    (new ConcurrentImpressionBatches)->run([
        ['user' => $users[0], 'batch_id' => (string) Str::uuid(), 'impressions' => ImpressionEvents::for($users[0], $commits)],
        ['user' => $users[1], 'batch_id' => (string) Str::uuid(), 'impressions' => ImpressionEvents::for($users[1], $commits->reverse())],
    ]);

    foreach ($commits->fresh() as $commit) {
        expect($commit->impressions_count)->toBe(2);
    }

    expect(CommitImpression::query()->count())->toBe(4)
        ->and(CommitImpressionBatch::query()->count())->toBe(2);
});
