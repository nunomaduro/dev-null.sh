<?php

declare(strict_types=1);

use App\Actions\CreateCommitImpressions;
use App\Models\Commit;
use App\Models\CommitImpression;
use App\Models\CommitImpressionBatch;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ImpressionEvents;

it('counts repeats from the last counted view at the exact thirty-minute boundary', function (): void {
    $this->freezeSecond();

    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $action = resolve(CreateCommitImpressions::class);
    $firstCountedAt = now();
    $firstEvents = ImpressionEvents::for($user, [$commit]);

    $action->handle($user, (string) Str::uuid(), $firstEvents);
    $this->travel(29)->minutes();
    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$commit]));
    $this->travel(59)->seconds();
    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$commit]));

    expect($commit->fresh()->impressions_count)->toBeOne()
        ->and(CommitImpression::query()->sole()->last_counted_at->equalTo($firstCountedAt))->toBeTrue();

    $this->travel(1)->seconds();
    $action->handle($user, (string) Str::uuid(), $firstEvents);
    expect($commit->fresh()->impressions_count)->toBeOne();

    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$commit]));

    expect($commit->fresh()->impressions_count)->toBe(2)
        ->and(CommitImpression::query()->sole()->last_counted_at->equalTo(now()))->toBeTrue();
});

it('never replays an accepted batch even after its cooldown has elapsed or its body changes', function (): void {
    $this->freezeSecond();

    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $other = Commit::factory()->create();
    $batchId = (string) Str::uuid();
    $action = resolve(CreateCommitImpressions::class);
    $firstCountedAt = now();

    $action->handle($user, $batchId, ImpressionEvents::for($user, [$commit]));
    $this->travel(31)->minutes();
    $action->handle($user, $batchId, ImpressionEvents::for($user, [$commit, $other]));

    expect($commit->fresh()->impressions_count)->toBeOne()
        ->and($other->fresh()->impressions_count)->toBe(0)
        ->and(CommitImpression::query()->sole()->last_counted_at->equalTo($firstCountedAt))->toBeTrue()
        ->and(CommitImpressionBatch::query()->count())->toBeOne();
});

it('scopes batch receipts and repeat cooldowns to each viewer', function (): void {
    $commit = Commit::factory()->create();
    $batchId = (string) Str::uuid();
    $action = resolve(CreateCommitImpressions::class);

    foreach (User::factory()->count(2)->create() as $user) {
        $action->handle($user, $batchId, ImpressionEvents::for($user, [$commit]));
        $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$commit]));
    }

    expect($commit->fresh()->impressions_count)->toBe(2)
        ->and(CommitImpression::query()->count())->toBe(2)
        ->and(CommitImpressionBatch::query()->count())->toBe(4);
});

it('rechecks visibility for subsequent batches', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $action = resolve(CreateCommitImpressions::class);

    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$commit]));
    $this->travel(31)->minutes();
    $user->blocking()->attach($commit->user);
    $action->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, [$commit]));

    expect($commit->fresh()->impressions_count)->toBeOne();
});

it('locks overlapping commits and viewer state in the same stable order', function (): void {
    $user = User::factory()->create();
    $commits = Commit::factory()->count(2)->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'for update')) {
            $queries[] = $query->sql;
        }
    });

    resolve(CreateCommitImpressions::class)->handle($user, (string) Str::uuid(), ImpressionEvents::for($user, $commits->reverse()));

    expect($queries)->toHaveCount(2)
        ->and($queries[0])->toContain('order by "id" asc for update')
        ->and($queries[1])->toContain('order by "commit_id" asc for update');
});

it('rolls back receipts and cooldowns with the counter if a batch fails and allows a clean retry', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $batchId = (string) Str::uuid();
    $action = resolve(CreateCommitImpressions::class);
    $fail = true;

    DB::listen(function (QueryExecuted $query) use (&$fail): void {
        if ($fail && str_starts_with($query->sql, 'update "commits" set "impressions_count"')) {
            $fail = false;

            throw new RuntimeException('Counter storage failed.');
        }
    });

    expect(fn () => $action->handle($user, $batchId, ImpressionEvents::for($user, [$commit])))->toThrow(RuntimeException::class, 'Counter storage failed.');

    expect($commit->fresh()->impressions_count)->toBe(0)
        ->and(CommitImpression::query()->count())->toBe(0)
        ->and(CommitImpressionBatch::query()->count())->toBe(0);

    $action->handle($user, $batchId, ImpressionEvents::for($user, [$commit]));

    expect($commit->fresh()->impressions_count)->toBeOne()
        ->and(CommitImpression::query()->count())->toBeOne()
        ->and(CommitImpressionBatch::query()->count())->toBeOne();
});

it('exposes integer zero on new commit instances', function (): void {
    expect((new Commit)->impressions_count)->toBe(0)
        ->and(Commit::factory()->create()->toArray()['impressions_count'])->toBe(0);
});
