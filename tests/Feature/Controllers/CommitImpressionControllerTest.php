<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Enums\Log;
use App\Models\Commit;
use App\Models\CommitImpression;
use App\Models\CommitImpressionBatch;
use App\Models\User;
use App\Services\CommitImpressionToken;
use Illuminate\Support\Str;
use Tests\Support\ImpressionEvents;

it('records an authenticated batch without touching commit edit timestamps', function (): void {
    $user = User::factory()->create();
    $commits = Commit::factory()->count(2)->create(['updated_at' => now()->subDay()]);
    $updatedAt = $commits->first()->updated_at;

    $this->actingAs($user)->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(),
        'impressions' => ImpressionEvents::for($user, $commits),
    ])->assertNoContent();

    expect(CommitImpression::query()->count())->toBe(2)
        ->and(CommitImpressionBatch::query()->count())->toBeOne();

    foreach ($commits->fresh() as $commit) {
        expect($commit->impressions_count)->toBeOne()
            ->and($commit->updated_at->equalTo($updatedAt))->toBeTrue();
    }
});

it('requires a signed-in viewer', function (): void {
    $commit = Commit::factory()->create();

    $this->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(),
        'impressions' => ImpressionEvents::for(User::factory()->create(), [$commit]),
    ])->assertUnauthorized();

    expect($commit->fresh()->impressions_count)->toBe(0)
        ->and(CommitImpressionBatch::query()->count())->toBe(0);
});

it('validates bounded distinct impressions with a required token', function (array $payload, string $error): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('commit-impression.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($error);

    expect(CommitImpressionBatch::query()->count())->toBe(0);
})->with([
    'missing batch' => [['impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000001', 'token' => 'token']]], 'batch_id'],
    'invalid batch' => [['batch_id' => 'invalid', 'impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000001', 'token' => 'token']]], 'batch_id'],
    'missing impressions' => [['batch_id' => '019a0000-0000-7000-8000-000000000001'], 'impressions'],
    'bare IDs' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'commit_ids' => ['019a0000-0000-7000-8000-000000000002']], 'impressions'],
    'empty list' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => []], 'impressions'],
    'object' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => ['key' => ['commit_id' => '019a0000-0000-7000-8000-000000000002', 'token' => 'token']]], 'impressions'],
    'invalid commit' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => [['commit_id' => 'invalid', 'token' => 'token']]], 'impressions.0.commit_id'],
    'duplicates' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000002', 'token' => 'one'], ['commit_id' => '019A0000-0000-7000-8000-000000000002', 'token' => 'two']]], 'impressions.0.commit_id'],
    'missing token' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000002']]], 'impressions.0.token'],
    'empty token' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000002', 'token' => '']]], 'impressions.0.token'],
    'nonstring token' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000002', 'token' => 1]]], 'impressions.0.token'],
    'oversize token' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000002', 'token' => str_repeat('a', 1025)]]], 'impressions.0.token'],
    'unexpected event fields' => [['batch_id' => '019a0000-0000-7000-8000-000000000001', 'impressions' => [['commit_id' => '019a0000-0000-7000-8000-000000000002', 'token' => 'token', 'count' => 100]]], 'impressions.0'],
    'too many impressions' => [['batch_id' => (string) Str::uuid(), 'impressions' => array_map(fn (): array => ['commit_id' => (string) Str::uuid(), 'token' => 'token'], range(1, 51))], 'impressions'],
]);

it('accepts up to fifty tokenized impressions in one batch', function (): void {
    $user = User::factory()->create();
    $author = User::factory()->create();
    $commits = Commit::factory()->for($author)->count(50)->create();

    $payload = ['batch_id' => (string) Str::uuid(), 'impressions' => ImpressionEvents::for($user, $commits)];
    expect(mb_strlen(json_encode($payload, JSON_THROW_ON_ERROR)))->toBeLessThan(65_536);

    $this->actingAs($user)->postJson(route('commit-impression.store'), $payload)->assertNoContent();

    expect(CommitImpression::query()->count())->toBe(50)
        ->and(Commit::query()->sum('impressions_count'))->toEqual(50);
});

it('rechecks own unapproved deleted blocked and nonexistent commits after token issuance', function (): void {
    $user = User::factory()->create();
    $commits = Commit::factory()->count(9)->create();
    [$approved, $anonymous, $own, $pending, $rejected, $deleted, $blocked, $blocking, $missing] = $commits->all();
    $events = ImpressionEvents::for($user, $commits);

    $anonymous->update(['user_id' => null]);
    $own->update(['user_id' => $user->id]);
    $pending->update(['status' => CommitStatus::Pending]);
    $rejected->update(['status' => CommitStatus::Rejected]);
    $deleted->delete();
    $missing->forceDelete();
    $user->blocking()->attach($blocked->user);
    $blocking->user->blocking()->attach($user);

    $this->actingAs($user)->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(),
        'impressions' => $events,
    ])->assertNoContent();

    expect($approved->fresh()->impressions_count)->toBeOne()
        ->and($anonymous->fresh()->impressions_count)->toBeOne()
        ->and(Commit::query()->withTrashed()->sum('impressions_count'))->toEqual(2)
        ->and(CommitImpression::query()->count())->toBe(2);
});

it('ignores forged and substituted tokens while counting valid events in the same batch', function (): void {
    $user = User::factory()->create();
    $otherViewer = User::factory()->create();
    $commits = Commit::factory()->count(5)->create();
    [$valid, $forged, $wrongViewer, $substituted, $source] = $commits->all();
    $events = [
        ...ImpressionEvents::for($user, [$valid]),
        ['commit_id' => $forged->id, 'token' => 'forged'],
        ...ImpressionEvents::for($otherViewer, [$wrongViewer]),
        ['commit_id' => $substituted->id, 'token' => ImpressionEvents::for($user, [$source])[0]['token']],
    ];

    $this->actingAs($user)->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(),
        'impressions' => $events,
    ])->assertNoContent();

    expect($valid->fresh()->impressions_count)->toBeOne()
        ->and(Commit::query()->sum('impressions_count'))->toEqual(1)
        ->and(CommitImpression::query()->count())->toBeOne();
});

it('rejects a token at the exact ten-minute expiry boundary', function (): void {
    $this->freezeSecond();
    $user = User::factory()->create();
    $commits = Commit::factory()->count(2)->create();
    $events = ImpressionEvents::for($user, $commits);

    $this->travel(599)->seconds();
    $this->actingAs($user)->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(), 'impressions' => [$events[0]],
    ])->assertNoContent();

    $this->travel(1)->seconds();
    $this->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(), 'impressions' => [$events[1]],
    ])->assertNoContent();

    expect($commits[0]->fresh()->impressions_count)->toBeOne()
        ->and($commits[1]->fresh()->impressions_count)->toBe(0);
});

it('issues usable viewer-bound tokens on the commit page including nested quotes and replies', function (): void {
    $this->withoutVite();
    $user = User::factory()->create();
    $quoted = Commit::factory()->create();
    $commit = Commit::factory()->create(['quoted_commit_id' => $quoted->id]);
    $reply = Commit::factory()->create(['parent_id' => $commit->id]);

    $response = $this->actingAs($user)->get(route('commit.show', [$commit->user, $commit]));
    $response->assertOk()->assertInertia(fn ($page) => $page
        ->where('commit.impression_token_expires_at', now()->addMinutes(10)->getTimestamp())
        ->where('commit.quoted_commit.impression_token_expires_at', now()->addMinutes(10)->getTimestamp())
        ->where('replies.data.0.impression_token_expires_at', now()->addMinutes(10)->getTimestamp()));

    $tokens = resolve(CommitImpressionToken::class);
    expect($tokens->validFor($user, $commit->id, $response->inertiaProps('commit.impression_token')))->toBeTrue()
        ->and($tokens->validFor($user, $quoted->id, $response->inertiaProps('commit.quoted_commit.impression_token')))->toBeTrue()
        ->and($tokens->validFor($user, $reply->id, $response->inertiaProps('replies.data.0.impression_token')))->toBeTrue();
});

it('shows totals publicly but issues no token to guests or the author', function (): void {
    $this->withoutVite();
    $commit = Commit::factory()->create(['impressions_count' => 12]);

    $this->get(route('user.show', $commit->user))
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.impressions_count', 12)
            ->where('commits.data.0.impression_token', null)
            ->where('commits.data.0.impression_token_expires_at', null));

    $this->actingAs($commit->user)->get(route('user.show', $commit->user))
        ->assertInertia(fn ($page) => $page
            ->where('commits.data.0.impressions_count', 12)
            ->where('commits.data.0.impression_token', null)
            ->where('commits.data.0.impression_token_expires_at', null));

    $this->actingAs(User::factory()->create())->get(route('log.show', Log::Trending))
        ->assertInertia(fn ($page) => $page->where('commits.data.0.impressions_count', 12));
});

it('gives each signed-in viewer a separate impression request budget', function (): void {
    $commit = Commit::factory()->create();
    $user = User::factory()->create();
    $events = ImpressionEvents::for($user, [$commit]);
    $this->actingAs($user);

    foreach (range(1, 60) as $attempt) {
        $this->postJson(route('commit-impression.store'), [
            'batch_id' => (string) Str::uuid(), 'impressions' => $events,
        ])->assertNoContent();
    }

    $this->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(), 'impressions' => $events,
    ])->assertTooManyRequests();

    $other = User::factory()->create();
    $this->actingAs($other)->postJson(route('commit-impression.store'), [
        'batch_id' => (string) Str::uuid(), 'impressions' => ImpressionEvents::for($other, [$commit]),
    ])->assertNoContent();

    expect($commit->fresh()->impressions_count)->toBe(2);
});
