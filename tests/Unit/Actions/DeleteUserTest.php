<?php

declare(strict_types=1);

use App\Actions\CreateCommitTranslation;
use App\Actions\DeleteUser;
use App\Actions\UpdateCommitHotScores;
use App\Ai\Agents\CommitTranslator;
use App\Dto\ThreadEngagement;
use App\Models\Commit;
use App\Models\CommitImage;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Device;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Models\MessageImage;
use App\Models\MessageVoice;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\Topic;
use App\Models\User;
use App\Services\CommitTranslations;
use App\Services\TheAlgorithm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('may delete a user', function (): void {
    $user = User::factory()->create(['translation_language' => 'ja', 'automatic_translation' => false]);

    $action = resolve(DeleteUser::class);

    $action->handle($user);

    expect($user->exists)->toBeFalse();
    $this->assertModelMissing($user);
});

it('erases the commits of a deleted user along with the replies under them', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create(['topic_decision' => true]);
    Commit::factory()->for($user)->create()->delete();
    Commit::factory()->for($commit, 'parent')->create();
    $unrelated = Commit::factory()->create();

    resolve(DeleteUser::class)->handle($user);

    expect(Commit::withTrashed()->pluck('id')->all())->toBe([$unrelated->id]);
});

it('erases topic associations and orphaned labels from deleted threads and retained reply tombstones', function (): void {
    $user = User::factory()->create();
    $root = Commit::factory()->for($user)->create();
    $child = Commit::factory()->for($root, 'parent')->create();
    $otherRoot = Commit::factory()->create();
    $reply = Commit::factory()->for($user)->for($otherRoot, 'parent')->create(['topic_decision' => true]);
    Commit::factory()->for($reply, 'parent')->create();
    $sole = Topic::factory()->create();
    $childOnly = Topic::factory()->create();
    $shared = Topic::factory()->create();
    $unrelated = Topic::factory()->create();
    $root->topics()->attach([$sole->id, $shared->id]);
    $reply->topics()->attach($sole);
    $child->topics()->attach($childOnly);
    $otherRoot->topics()->attach($shared);

    resolve(DeleteUser::class)->handle($user);

    $this->assertDatabaseMissing('commit_topic', ['commit_id' => $root->id]);
    $this->assertDatabaseMissing('commit_topic', ['commit_id' => $child->id]);
    $this->assertDatabaseMissing('commit_topic', ['commit_id' => $reply->id]);
    $this->assertModelMissing($sole);
    $this->assertModelMissing($childOnly);
    $this->assertModelExists($shared);
    $this->assertModelExists($unrelated);
    expect($otherRoot->topics()->pluck('topics.id')->all())->toBe([$shared->id])
        ->and(Commit::withTrashed()->findOrFail($reply->id))
        ->topic_decision->toBeNull();
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

it('erases what a deleted user wrote from the tombstones they leave', function (): void {
    $user = User::factory()->create();
    $mentioned = User::factory()->create();
    $commit = Commit::factory()->create();
    $reply = Commit::factory()->for($user)->for($commit, 'parent')->for(LinkPreview::factory())->create([
        'body' => "@{$mentioned->username} have a look at https://example.com",
        'mentions' => [$mentioned->username => $mentioned->id],
        'embedding' => embeddingVector(0),
    ]);
    $deletedReply = Commit::factory()->for($user)->for($commit, 'parent')->create();
    Commit::factory()->for($reply, 'parent')->create();
    Commit::factory()->for($deletedReply, 'parent')->create();
    $deletedReply->delete();

    resolve(DeleteUser::class)->handle($user);

    $tombstones = Commit::withTrashed()->whereKey([$reply->id, $deletedReply->id]);

    expect($tombstones->count())->toBe(2)
        ->and($tombstones->pluck('body')->all())->toBe(['', ''])
        ->and($tombstones->pluck('mentions')->all())->toBe([null, null])
        ->and($tombstones->pluck('embedding')->all())->toBe([null, null])
        ->and($tombstones->pluck('link_preview_id')->all())->toBe([null, null]);
});

it('erases what deleted accounts wrote from the tombstones they already left', function (): void {
    $mentioned = User::factory()->create();
    $orphan = Commit::factory()->for(LinkPreview::factory())->create([
        'user_id' => null,
        'mentions' => [$mentioned->username => $mentioned->id],
        'embedding' => embeddingVector(0),
        'deleted_at' => now(),
    ]);
    $deleted = Commit::factory()->create(['deleted_at' => now()]);
    $live = Commit::factory()->create();

    $migration = require database_path('migrations/2026_10_20_092957_erase_the_body_of_orphaned_commits.php');
    $migration->up();

    $tombstone = Commit::withTrashed()->whereKey($orphan->id);

    expect($tombstone->value('body'))->toBe('')
        ->and($tombstone->value('mentions'))->toBeNull()
        ->and($tombstone->value('embedding'))->toBeNull()
        ->and($tombstone->value('link_preview_id'))->toBeNull()
        ->and(Commit::withTrashed()->whereKey($deleted->id)->value('body'))->toBe($deleted->body)
        ->and(Commit::withTrashed()->whereKey($live->id)->value('body'))->toBe($live->body);
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

it('erases the mutes, blocks and dismissed suggestions of a deleted user in both directions', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $user->muting()->attach($other);
    $other->muting()->attach($user);
    $user->blocking()->attach($other);
    $other->blocking()->attach($user);
    $user->dismissedSuggestions()->attach($other);
    $other->dismissedSuggestions()->attach($user);

    resolve(DeleteUser::class)->handle($user);

    expect($other->muting()->count())->toBe(0)
        ->and($other->blocking()->count())->toBe(0)
        ->and($other->blockers()->count())->toBe(0)
        ->and($other->dismissedSuggestions()->count())->toBe(0)
        ->and(DB::table('suggestion_dismissals')->count())->toBe(0);

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

it('erases the devices of a deleted user', function (): void {
    $user = User::factory()->create();
    Device::factory()->for($user)->create();
    $other = Device::factory()->create();

    resolve(DeleteUser::class)->handle($user);

    expect(Device::query()->pluck('id')->all())->toBe([$other->id]);
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

it('erases the images a deleted user sent in remotes, keeping the images sent to them', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $sent = MessageImage::factory()->for(Message::factory()->for($user))->create();
    $received = MessageImage::factory()->create();
    Storage::disk()->put($sent->path, 'image');
    Storage::disk()->put($received->path, 'image');

    resolve(DeleteUser::class)->handle($user);

    expect(Storage::disk()->allFiles('message-images'))->toBe([$received->path])
        ->and(MessageImage::query()->pluck('id')->all())->toBe([$received->id]);
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
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($starred->created_at, ThreadEngagement::none()), 1e-9)
        ->and($repliedTo->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($repliedTo->created_at, new ThreadEngagement(stargazers: 0, quoters: 0, repliers: 1, repliersAnsweredByAuthor: 0)), 1e-9);
});

it('lowers the threads the deleted user quoted', function (): void {
    $user = User::factory()->create();
    $commit = Commit::factory()->create();
    Commit::factory()->for($user)->for($commit, 'quotedCommit')->create();
    resolve(UpdateCommitHotScores::class)->handle([$commit->id]);

    resolve(DeleteUser::class)->handle($user);

    expect($commit->fresh()->hot_score)
        ->toEqualWithDelta(resolve(TheAlgorithm::class)->score($commit->created_at, ThreadEngagement::none()), 1e-9);
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

it('erases cached translations of the user and replies in their deleted threads', function (): void {
    CommitTranslator::fake([
        ['source_language' => 'de', 'body' => 'Good morning'],
        ['source_language' => 'nl', 'body' => 'Good evening'],
    ]);
    $user = User::factory()->create();
    $commit = Commit::factory()->for($user)->create(['body' => 'Guten morgen']);
    Commit::factory()->for($user)->count(500)->create(['body' => 'Other posts']);
    $reply = Commit::factory()->for($commit, 'parent')->create(['body' => 'Goedenavond', 'root_id' => $commit->id]);
    $unrelated = Commit::factory()->create(['body' => 'Unrelated post']);
    resolve(CreateCommitTranslation::class)->handle($commit, 'en');
    resolve(CreateCommitTranslation::class)->handle($reply, 'en');

    $unrelatedKey = resolve(CommitTranslations::class)->key($unrelated);
    Cache::put($unrelatedKey, ['source_language' => 'en', 'translations' => [], 'expires_at' => now()->addDay()->getTimestamp()], 60);

    DB::enableQueryLog();
    DB::flushQueryLog();
    resolve(DeleteUser::class)->handle($user);
    $bodyReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'select "id", "body" from "commits"'));
    DB::disableQueryLog();

    expect(resolve(CommitTranslations::class)->cached($commit))->toBeNull()
        ->and(resolve(CommitTranslations::class)->cached($reply))->toBeNull()
        ->and(Cache::has($unrelatedKey))->toBeTrue()
        ->and($bodyReads)->toHaveCount(2);
    $this->assertModelMissing($user);
});

it('erases the voice notes a deleted user sent in remotes, keeping the ones sent to them', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    $sent = MessageVoice::factory()->for(Message::factory()->for($user))->create();
    $received = MessageVoice::factory()->create();
    Storage::disk()->put($sent->path, 'voice');
    Storage::disk()->put($received->path, 'voice');

    resolve(DeleteUser::class)->handle($user);

    expect(Storage::disk()->allFiles('message-voices'))->toBe([$received->path])
        ->and(MessageVoice::query()->pluck('id')->all())->toBe([$received->id]);
});

it('erases the orgs a deleted user was the last one in, with the images and voice notes sent in them', function (): void {
    Storage::fake();

    $user = User::factory()->create();
    [$former] = followersOf($user, 1);
    $emptied = createOrg($user, [$former]);
    $kept = createOrg($user, [$former]);
    $image = MessageImage::factory()->for(pushToOrg($former, $emptied))->create();
    $voice = MessageVoice::factory()->for(pushToOrg($former, $emptied))->create();
    $other = MessageVoice::factory()->for(pushToOrg($former, $kept))->create();
    foreach ([$image, $voice, $other] as $file) {
        Storage::disk()->put($file->path, 'file');
    }

    $this->actingAs($former)->deleteJson(route('org-member.destroy', [$emptied, $former]))->assertNoContent();
    $this->actingAs($user)->putJson(route('org-mute.update', $kept), ['duration' => 'forever'])->assertOk();
    $this->actingAs($former)->putJson(route('org-mute.update', $kept), ['duration' => 'week'])->assertOk();

    $this->actingAs($user)->putJson(route('org-pin.update', $kept), ['pinned' => true])->assertOk();
    $this->actingAs($former)->putJson(route('org-pin.update', $kept), ['pinned' => true])->assertOk();

    resolve(DeleteUser::class)->handle($user);

    expect(ConversationParticipant::query()->whereNotNull('pinned_at')->pluck('user_id')->all())->toBe([$former->id]);
    expect(Conversation::query()->pluck('id')->all())->toBe([$kept])
        ->and(Storage::disk()->allFiles())->toBe([$other->path])
        ->and(ConversationParticipant::query()->whereNotNull('muted_until_at')->pluck('user_id')->all())->toBe([$former->id]);
});
