<?php

declare(strict_types=1);

use App\Enums\CommitStatus;
use App\Models\Commit;
use App\Models\User;
use App\Moderation\DeterministicModerationSignals;

it('hashes bodies after normalizing case and whitespace', function (): void {
    $signals = resolve(DeterministicModerationSignals::class);

    expect($signals->bodyHash("  Hello   World\n"))
        ->toBe($signals->bodyHash('hello world'));
});

it('flags duplicate pending bodies', function (): void {
    $hash = resolve(DeterministicModerationSignals::class)->bodyHash('same message');

    Commit::factory()->pending()->create([
        'body' => 'same message',
        'body_hash' => $hash,
    ]);

    $commit = Commit::factory()->pending()->create([
        'body' => 'Same   Message',
        'body_hash' => $hash,
    ]);

    $codes = array_column(resolve(DeterministicModerationSignals::class)->handle($commit), 'code');

    expect($codes)->toContain('duplicate');
});

it('flags duplicates of rejected bodies', function (): void {
    $hash = resolve(DeterministicModerationSignals::class)->bodyHash('rejected twin');

    Commit::factory()->rejected()->create([
        'body' => 'rejected twin',
        'body_hash' => $hash,
    ]);

    $commit = Commit::factory()->pending()->create([
        'body' => 'rejected twin',
        'body_hash' => $hash,
    ]);

    $codes = array_column(resolve(DeterministicModerationSignals::class)->handle($commit), 'code');

    expect($codes)->toContain('duplicate');
});

it('flags author flood when pending commits meet the threshold', function (): void {
    config(['moderation.author_flood_threshold' => 3]);

    $user = User::factory()->create();
    Commit::factory()->pending()->for($user)->count(3)->create();

    $commit = Commit::query()->where('user_id', $user->id)->pending()->latest('id')->firstOrFail();

    $codes = array_column(resolve(DeterministicModerationSignals::class)->handle($commit), 'code');

    expect($codes)->toContain('author_flood');
});

it('flags burst posting', function (): void {
    config([
        'moderation.burst.count' => 3,
        'moderation.burst.minutes' => 10,
    ]);

    $user = User::factory()->create();
    Commit::factory()->for($user)->count(3)->create([
        'status' => CommitStatus::Pending,
        'created_at' => now()->subMinute(),
    ]);

    $commit = Commit::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

    $codes = array_column(resolve(DeterministicModerationSignals::class)->handle($commit), 'code');

    expect($codes)->toContain('burst');
});

it('flags urls', function (): void {
    $commit = Commit::factory()->pending()->create([
        'body' => 'Check https://example.com/path',
    ]);

    $codes = array_column(resolve(DeterministicModerationSignals::class)->handle($commit), 'code');

    expect($codes)->toContain('has_url');
});

it('flags invite-like copy', function (): void {
    $commit = Commit::factory()->pending()->create([
        'body' => 'Join my discord for free tips',
    ]);

    $codes = array_column(resolve(DeterministicModerationSignals::class)->handle($commit), 'code');

    expect($codes)->toContain('invite_like');
});

it('returns no signals for ordinary text', function (): void {
    $commit = Commit::factory()->pending()->create([
        'body' => 'Shipped a quiet refactor of the log query.',
    ]);

    expect(resolve(DeterministicModerationSignals::class)->handle($commit))->toBe([]);
});
