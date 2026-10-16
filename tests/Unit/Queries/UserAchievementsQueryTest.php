<?php

declare(strict_types=1);

use App\Enums\Achievement;
use App\Enums\CommitGift;
use App\Enums\CommitStatus;
use App\Enums\UnixRank;
use App\Models\Commit;
use App\Models\CommitTip;
use App\Models\Shadow;
use App\Models\SshKey;
use App\Models\User;
use App\Queries\UserAchievementsQuery;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
});

it('calculates initial rank and zero progress for a new user without activity', function (): void {
    $user = User::factory()->create();

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    expect($result['level'])->toBe(1)
        ->and($result['rank'])->toBe(UnixRank::User->value)
        ->and($result['xp'])->toBe(0)
        ->and($result['next_level_xp'])->toBe(40)
        ->and($result['level_progress'])->toBe(0)
        ->and($result['ranks'])->toHaveCount(6)
        ->and($result['xp_breakdown']['commits']['xp'])->toBe(0)
        ->and($result['badges'])->toHaveCount(11);

    foreach ($result['badges'] as $badge) {
        expect($badge['unlocked'])->toBeFalse()
            ->and($badge['progress'])->toBe(0);
    }
});

it('unlocks init badge upon first commit and awards xp', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->create();

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $initBadge = collect($result['badges'])->firstWhere('id', Achievement::Init->value);

    expect($initBadge)->not->toBeNull()
        ->and($initBadge['unlocked'])->toBeTrue()
        ->and($initBadge['progress'])->toBe(1)
        // XP: 10 (commit) + 15 (1-day streak) = 25
        ->and($result['xp'])->toBe(25);
});

it('unlocks authorized_keys badge when user has linked public ssh keys from github', function (): void {
    $user = User::factory()->create();

    SshKey::factory()->for($user)->create();

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $badge = collect($result['badges'])->firstWhere('id', Achievement::AuthorizedKeys->value);

    expect($badge)->not->toBeNull()
        ->and($badge['unlocked'])->toBeTrue()
        ->and($badge['progress'])->toBe(1);
});

it('tracks stars given to other developers and excludes self-starring', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $userCommit = Commit::factory()->for($user)->create();
    $otherCommit = Commit::factory()->for($other)->create();

    // User stars their own commit (must be ignored)
    $user->starredCommits()->attach($userCommit);

    // User stars other's commit (must be counted)
    $user->starredCommits()->attach($otherCommit);

    // Other stars user's commit (counted for user's stars received)
    $other->starredCommits()->attach($userCommit);

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $stargazer = collect($result['badges'])->firstWhere('id', Achievement::Stargazer->value);
    $superstar = collect($result['badges'])->firstWhere('id', Achievement::Superstar->value);

    // Stargazer should be exactly 1 (excluding self-star)
    expect($stargazer['progress'])->toBe(1)
        ->and($stargazer['unlocked'])->toBeFalse()
        // Superstar should be exactly 1 (excluding self-star)
        ->and($superstar['progress'])->toBe(1)
        ->and($superstar['unlocked'])->toBeFalse()
        // XP: 10 (commit) + 15 (1-day streak) + 5 (1 star received) + 2 (1 star given) = 32
        ->and($result['xp'])->toBe(32);
});

it('unlocks pipe badge with replies to other developers and excludes self-replies', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $userCommit = Commit::factory()->for($user)->create();
    $otherCommit = Commit::factory()->for($other)->create();

    // 3 replies to self (must be excluded)
    Commit::factory()->for($user)->for($userCommit, 'parent')->count(3)->create();

    // 5 replies to other developer's commit (valid conversation replies)
    Commit::factory()->for($user)->for($otherCommit, 'parent')->count(5)->create();

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $pipeBadge = collect($result['badges'])->firstWhere('id', Achievement::Pipe->value);

    // Total replies count is 5, not 8
    expect($pipeBadge['unlocked'])->toBeTrue()
        ->and($pipeBadge['progress'])->toBe(5)
        ->and($result['xp_breakdown']['replies']['count'])->toBe(5)
        ->and($result['xp_breakdown']['replies']['xp'])->toBe(25);
});

it('counts replies even if the parent commit was soft deleted', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $otherCommit = Commit::factory()->for($other)->create();
    Commit::factory()->for($user)->for($otherCommit, 'parent')->create();

    // Soft delete the parent commit
    $otherCommit->delete();

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $pipeBadge = collect($result['badges'])->firstWhere('id', Achievement::Pipe->value);

    expect($pipeBadge['progress'])->toBe(1)
        ->and($result['xp_breakdown']['replies']['count'])->toBe(1);
});

it('unlocks uptime badge for 7-day streak and calculates streak xp', function (): void {
    $user = User::factory()->create();

    foreach (range(0, 6) as $day) {
        Commit::factory()->for($user)->create(['created_at' => now()->subDays($day)]);
    }

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $uptime = collect($result['badges'])->firstWhere('id', Achievement::Uptime->value);

    expect($uptime['unlocked'])->toBeTrue()
        ->and($uptime['progress'])->toBe(7)
        // Seven approved commits on seven days plus streak XP: 175 XP, Level 3 (user rank).
        ->and($result['xp'])->toBe(175)
        ->and($result['level'])->toBe(3)
        ->and($result['rank'])->toBe(UnixRank::User->value);
});

it('uses a hundred steep levels and six unix rank milestones', function (): void {
    $user = User::factory()->create();
    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    expect($result)->toMatchArray(['level' => 1, 'rank' => 'user', 'next_level_xp' => 40]);
    expect(array_column($result['ranks'], 'level'))->toBe([1, 10, 25, 45, 70, 100]);
});

it('caps contribution XP at three approved posts per day', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->count(20)->create();

    $data = resolve(UserAchievementsQuery::class)->get($user, $user);
    expect($data['xp_breakdown']['commits'])->toBe(['count' => 3, 'multiplier' => 10, 'xp' => 30])
        ->and($data['xp'])->toBe(45);
});

it('respects visibility scope of viewer for unapproved commits', function (): void {
    $user = User::factory()->create();
    $viewer = User::factory()->create();

    Commit::factory()->for($user)->create(['status' => CommitStatus::Pending]);

    $query = resolve(UserAchievementsQuery::class);

    // Author sees their own pending commit
    $authorResult = $query->get($user, $user);
    expect($authorResult['xp'])->toBe(0);

    // Another viewer does not see pending commit
    $viewerResult = $query->get($user, $viewer);
    expect($viewerResult['xp'])->toBe(0);
});

it('unlocks syntax badge when a commit contains code blocks', function (): void {
    $user = User::factory()->create();

    Commit::factory()->for($user)->create([
        'body' => "Check out this code:\n```php\necho 'hello world';\n```",
    ]);

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $syntaxBadge = collect($result['badges'])->firstWhere('id', Achievement::Syntax->value);

    expect($syntaxBadge)->not->toBeNull()
        ->and($syntaxBadge['unlocked'])->toBeTrue()
        ->and($syntaxBadge['progress'])->toBe(1);
});

it('tracks sysadmin badge progress up to 100-day streak', function (): void {
    $user = User::factory()->create();

    foreach (range(0, 10) as $day) {
        Commit::factory()->for($user)->create(['created_at' => now()->subDays($day)]);
    }

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);

    $sysadminBadge = collect($result['badges'])->firstWhere('id', Achievement::Sysadmin->value);

    expect($sysadminBadge)->not->toBeNull()
        ->and($sysadminBadge['unlocked'])->toBeFalse()
        ->and($sysadminBadge['progress'])->toBe(11)
        ->and($sysadminBadge['target'])->toBe(100);
});

it('reuses passed streaks without re-running streak calculation', function (): void {
    $user = User::factory()->create();
    Commit::factory()->for($user)->create();

    $mockStreaks = ['longest' => 42, 'current' => 5];
    $result = resolve(UserAchievementsQuery::class)->get($user, $user, $mockStreaks);

    expect($result['xp_breakdown']['streak']['count'])->toBe(1)
        ->and($result['xp_breakdown']['streak']['xp'])->toBe(15);
});

it('awards one-time social XP per distinct contributor and unlocked skin', function (): void {
    $user = User::factory()->create();
    $peer = User::factory()->create();
    $third = User::factory()->create();
    $ownPost = Commit::factory()->for($user)->create();
    $peerPost = Commit::factory()->for($peer)->create();

    // The user can exchange multiple gifts with the same peer, but only
    // the first qualifying relationship earns XP in either direction.
    CommitTip::factory()->count(3)->create([
        'sender_id' => $user->id,
        'recipient_id' => $peer->id,
        'commit_id' => $peerPost->id,
        'gift_type' => CommitGift::Coffee,
    ]);

    CommitTip::factory()->count(2)->create([
        'sender_id' => $peer->id,
        'recipient_id' => $user->id,
        'commit_id' => $ownPost->id,
        'gift_type' => CommitGift::Coffee,
    ]);

    // Another distinct contributor is another eligible reward.
    CommitTip::factory()->create([
        'sender_id' => $third->id,
        'recipient_id' => $user->id,
        'commit_id' => $ownPost->id,
        'gift_type' => CommitGift::Coffee,
    ]);

    Shadow::factory()->create([
        'user_id' => $user->id,
        'unlocked_skins' => ['classic-green', 'amber-crt', 'matrix'],
    ]);

    $data = resolve(UserAchievementsQuery::class)->get($user, $user, ['longest' => 0, 'current' => 0]);

    expect($data['xp_breakdown']['kudos_given'])->toBe(['count' => 1, 'multiplier' => 5, 'xp' => 5])
        ->and($data['xp_breakdown']['kudos_received'])->toBe(['count' => 2, 'multiplier' => 5, 'xp' => 10])
        ->and($data['xp_breakdown']['skins_unlocked'])->toBe(['count' => 2, 'multiplier' => 10, 'xp' => 20])
        ->and($data['xp'])->toBe(60);
});

it('does not grant social XP for pending, rejected or deleted commits', function (): void {
    $user = User::factory()->create();
    $peer = User::factory()->create();
    $pending = Commit::factory()->for($peer)->pending()->create();
    $rejected = Commit::factory()->for($peer)->create(['status' => CommitStatus::Rejected]);
    $deleted = Commit::factory()->for($peer)->create();
    $deleted->delete();

    foreach ([$pending, $rejected, $deleted] as $commit) {
        CommitTip::factory()->create([
            'sender_id' => $user->id,
            'recipient_id' => $peer->id,
            'commit_id' => $commit->id,
        ]);
    }

    $data = resolve(UserAchievementsQuery::class)->get($user, $user, ['longest' => 0, 'current' => 0]);

    expect($data['xp_breakdown']['kudos_given']['xp'])->toBe(0)
        ->and($data['xp_breakdown']['skins_unlocked']['xp'])->toBe(0)
        ->and($data['xp'])->toBe(0);
});
