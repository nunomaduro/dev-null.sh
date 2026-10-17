<?php

declare(strict_types=1);

use App\Actions\DeleteUser;
use App\Actions\UpdateCommitHotScores;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use App\Services\TheAlgorithm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('may delete a user', function (): void {
    $user = User::factory()->create();

    $action = resolve(DeleteUser::class);

    $action->handle($user);

    expect($user->exists)->toBeFalse();
});

it('erases the commits of a deleted user along with the replies under them', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    Commit::factory()->for($user)->create()->delete();
    Commit::factory()->for($commit, 'parent')->create();
    $unrelated = Commit::factory()->create();

    resolve(DeleteUser::class)->handle($user);

    expect(Commit::withTrashed()->pluck('id')->all())->toBe([$unrelated->id]);
});

it('keeps the quotes of others when erasing the quoted commits of a deleted user', function (): void {
    $user = User::factory()->create();
    $quote = Commit::factory()->for(Commit::factory()->for($user), 'quotedCommit')->create();

    resolve(DeleteUser::class)->handle($user);

    $this->assertNotSoftDeleted($quote);

    expect($quote->refresh()->quoted_commit_id)->toBeNull();
});

it('leaves a tombstone for a reply of a deleted user that other people replied to', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($user)->for($commit, 'parent')->create();
    $otherReply = Commit::factory()->for($reply, 'parent')->create();
    $nestedReply = Commit::factory()->for($user)->for($otherReply, 'parent')->create();

    resolve(DeleteUser::class)->handle($user);

    $tombstone = Commit::withTrashed()->findOrFail($reply->id);

    expect($tombstone->trashed())->toBeTrue()
        ->and($tombstone->user_id)->toBeNull()
        ->and($tombstone->parent_id)->toBe($commit->id)
        ->and(Commit::query()->pluck('id')->sort()->values()->all())->toBe(collect([$commit->id, $otherReply->id])->sort()->values()->all())
        ->and(Commit::withTrashed()->whereKey($nestedReply->id)->exists())->toBeFalse();
});

it('erases the replies of a deleted user that only have their own replies under them', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($user)->for($commit, 'parent')->create();
    Commit::factory()->for($user)->for($reply, 'parent')->create();
    Commit::factory()->for($user)->for($commit, 'parent')->create()->delete();

    resolve(DeleteUser::class)->handle($user);

    expect(Commit::withTrashed()->pluck('id')->all())->toBe([$commit->id]);

});

it('erases the mutes and blocks of a deleted user in both directions', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $user->muting()->attach($other);
    $other->muting()->attach($user);
    $user->blocking()->attach($other);
    $other->blocking()->attach($user);

    resolve(DeleteUser::class)->handle($user);

    expect($other->muting()->count())->toBe(0)
        ->and($other->blocking()->count())->toBe(0)
        ->and($other->blockers()->count())->toBe(0);

});

it('erases authored polls and the users votes while keeping other polls and voters', function (): void {
    $user = User::factory()->create();
    $poll = Poll::factory()->for(Commit::factory()->for($user))->withOptions('Tabs', 'Spaces')->create();
    $deletedPoll = Poll::factory()->for(Commit::factory()->for($user)->state(['deleted_at' => now()]))->withOptions('Vim', 'Emacs')->create();
    $other = Poll::factory()->withOptions('PHP', 'TypeScript')->create();
    PollVote::factory()->for($poll->options[0], 'option')->create();
    PollVote::factory()->for($deletedPoll->options[0], 'option')->create();
    PollVote::factory()->for($other->options[0], 'option')->for($user)->create();
    $keptVote = PollVote::factory()->for($other->options[1], 'option')->create();

    resolve(DeleteUser::class)->handle($user);

    expect(Poll::query()->sole()->is($other))->toBeTrue()
        ->and(PollOption::query()->pluck('id')->sort()->values()->all())->toBe($other->options->pluck('id')->sort()->values()->all())
        ->and(PollVote::query()->sole()->is($keptVote))->toBeTrue();
});

it('erases the images of a deleted user and of the replies under their commits', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create();
    $reply = Commit::factory()->for($commit, 'parent')->create();
    $nestedReply = Commit::factory()->for($reply, 'parent')->create();
    $otherCommit = Commit::factory()->create();
    $tombstonedReply = Commit::factory()->for($user)->for($otherCommit, 'parent')->create();
    $otherReply = Commit::factory()->for($tombstonedReply, 'parent')->create();

    $images = collect([$commit, $reply, $nestedReply, $tombstonedReply, $otherCommit, $otherReply])
        ->map(fn (Commit $commit): CommitImage => CommitImage::factory()->for($commit)->create())
        ->each(fn (CommitImage $image): bool => Storage::disk()->put($image->path, 'image'));

    resolve(DeleteUser::class)->handle($user);

    $kept = $images->slice(4)->map(fn (CommitImage $image): string => $image->path)->sort()->values()->all();

    expect(collect(Storage::disk()->allFiles('commit-images'))->sort()->values()->all())->toBe($kept)
        ->and(CommitImage::query()->pluck('path')->sort()->values()->all())->toBe($kept);
});

it('lowers the threads the deleted user starred or replied to', function (): void {
    $user = User::factory()->create();
    $starred = Commit::factory()->create();
    $starred->stars()->attach($user);
    $repliedTo = Commit::factory()->create();
    Commit::factory()->for($user)->for(Commit::factory()->for($repliedTo, 'parent'), 'parent')->create();
    resolve(UpdateCommitHotScores::class)->handle([$starred->id, $repliedTo->id]);

    resolve(DeleteUser::class)->handle($user);

    expect($starred->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($starred->created_at, 0, 0), 1e-9)
        ->and($repliedTo->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($repliedTo->created_at, 0, 1), 1e-9);
});

it('lowers the threads the deleted user quoted', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    Commit::factory()->for($user)->for($commit, 'quotedCommit')->create();
    resolve(UpdateCommitHotScores::class)->handle([$commit->id]);

    resolve(DeleteUser::class)->handle($user);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, 0, 0), 1e-9);
});

it('erases however many commits a user has without listing them in its queries', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $commits = Commit::factory()->for($user)->count(10)->create();
    $commits->each(fn (Commit $commit): Commit => Commit::factory()->for($commit, 'parent')->create());

    $repliedTo = Commit::factory()->create();
    $otherReply = Commit::factory()->for(Commit::factory()->for($user)->for($repliedTo, 'parent'), 'parent')->create();

    DB::enableQueryLog();

    resolve(DeleteUser::class)->handle($user);

    expect(collect(DB::getQueryLog())->max(fn (array $query): int => count($query['bindings'])))->toBeLessThan(10)
        ->and(Commit::query()->pluck('id')->sort()->values()->all())->toBe(collect([$repliedTo->id, $otherReply->id])->sort()->values()->all());
});
