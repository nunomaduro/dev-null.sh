<?php

declare(strict_types=1);

use App\Actions\CreateCommitImpressions;
use App\Models\Commit;
use App\Models\CommitImpression;
use App\Models\CommitImpressionBatch;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\ImpressionEvents;

it('prunes stale receipts and cooldowns without changing totals or a current repeat window', function (): void {
    $user = User::factory()->create();
    $stale = Commit::factory()->create();
    $current = Commit::factory()->create();
    $action = resolve(CreateCommitImpressions::class);

    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$stale]));
    $this->travel(25)->hours();
    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$current]));
    $this->artisan('commits:prune-impressions')->assertSuccessful();

    expect(CommitImpression::query()->sole()->commit_id)->toBe($current->id)
        ->and(CommitImpressionBatch::query()->count())->toBeOne()
        ->and($stale->fresh()->impressions_count)->toBeOne()
        ->and($current->fresh()->impressions_count)->toBeOne();

    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$stale, $current]));

    expect($stale->fresh()->impressions_count)->toBe(2)
        ->and($current->fresh()->impressions_count)->toBeOne();
});

it('cascades private impression state when a viewer or commit is permanently deleted', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    resolve(CreateCommitImpressions::class)->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$commit]));

    $commit->forceDelete();
    expect(CommitImpression::query()->count())->toBe(0)
        ->and(CommitImpressionBatch::query()->count())->toBeOne();

    $user->delete();
    expect(CommitImpressionBatch::query()->count())->toBe(0);
});

it('keeps receipts and cooldowns through the full retention boundary', function (): void {
    $this->freezeSecond();
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $cutoff = now()->subDay();
    $impression = CommitImpression::factory()->create(['user_id' => $user->id, 'commit_id' => $commit->id, 'last_counted_at' => $cutoff]);
    $receipt = CommitImpressionBatch::factory()->create(['user_id' => $user->id, 'created_at' => $cutoff]);

    $this->artisan('commits:prune-impressions')->assertSuccessful();

    expect($impression->fresh()->last_counted_at->equalTo($cutoff))->toBeTrue()
        ->and($receipt->fresh()->created_at->equalTo($cutoff))->toBeTrue();

    $this->travel(1)->seconds();
    $this->artisan('commits:prune-impressions')->assertSuccessful();

    expect(CommitImpression::query()->count())->toBe(0)
        ->and(CommitImpressionBatch::query()->count())->toBe(0);
});
