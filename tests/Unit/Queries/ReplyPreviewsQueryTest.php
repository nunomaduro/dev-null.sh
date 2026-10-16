<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;
use App\Queries\ReplyPreviewsQuery;
use Illuminate\Support\Facades\DB;

it('loads the reply previews of commits as commits, with what their cards show the viewer', function (): void {
    $viewer = User::factory()->create();
    $commit = Commit::factory()->create(['created_at' => now()->subMinutes(2)]);
    $reply = Commit::factory()->for($commit, 'parent')->create(['created_at' => now()->subMinute()]);
    $reply->stars()->attach($viewer);
    $quiet = Commit::factory()->create();

    $commits = Commit::query()->whereKey([$commit->id, $quiet->id])->withVisibleReplyCount($viewer)->withReplyPreviews($viewer)->latest()->get();

    resolve(ReplyPreviewsQuery::class)->load($commits, $viewer);

    expect($commits[0]->getRelation('replyPreview'))->toHaveCount(0)
        ->and($commits[1]->getRelation('replyPreview')->modelKeys())->toBe([$reply->id])
        ->and($commits[1]->getRelation('replyPreview')->sole()->starred)->toBeTrue()
        ->and($commits[1]->getRelation('replyPreview')->sole()->relationLoaded('user'))->toBeTrue();
});

it('leaves commits without a reply preview alone', function (): void {
    $commit = Commit::factory()->create();
    Commit::factory()->for($commit, 'parent')->create();

    $commits = Commit::query()->whereKey($commit->id)->get();

    resolve(ReplyPreviewsQuery::class)->load($commits, null);

    expect($commits->sole()->relationLoaded('replyPreview'))->toBeFalse();
});

it('loads every reply preview in one query, however many commits there are', function (): void {
    $queries = function (): int {
        $commits = Commit::query()->topLevel()->withVisibleReplyCount(null)->withReplyPreviews(null)->get();

        DB::flushQueryLog();
        DB::enableQueryLog();

        resolve(ReplyPreviewsQuery::class)->load($commits, null);

        return count(DB::getQueryLog());
    };

    $parents = Commit::factory()->count(2)->create();
    $parents->each(fn (Commit $parent) => Commit::factory()->for($parent, 'parent')->create());

    $few = $queries();

    $parents = Commit::factory()->count(6)->create();
    $parents->each(fn (Commit $parent) => Commit::factory()->for($parent, 'parent')->create());

    expect($queries())->toBe($few);
});
