<?php

declare(strict_types=1);

use App\Enums\Achievement;
use App\Enums\CommitStatus;
use App\Enums\UnixRank;
use App\Models\Commit;
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
        ->and($result['next_level_xp'])->toBe(50)
        ->and($result['level_progress'])->toBe(0)
        ->and($result['ranks'])->toHaveCount(6)
        ->and($result['xp_breakdown']['pasteis_received']['xp'])->toBe(0)
        ->and($result['badges'])->toHaveCount(12);

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
        // 7 commits * 10 = 70 XP, 7 longest streak * 15 = 105 XP -> Total 175 XP -> Level 3 Operator
        ->and($result['xp'])->toBe(175)
        ->and($result['level'])->toBe(3)
        ->and($result['rank'])->toBe(UnixRank::Operator->value);
});

it('resolves correct unix ranks across all progression boundaries', function (): void {
    $user = User::factory()->create();
    $query = resolve(UserAchievementsQuery::class);

    // Level 1: user (0 - 49 XP)
    expect($query->get($user, $user, ['longest' => 0, 'current' => 0]))
        ->toMatchArray(['level' => 1, 'rank' => 'user', 'next_level_xp' => 50, 'level_progress' => 0]);

    // Level 2: daemon (50 - 149 XP)
    // 5 commits = 50 XP
    Commit::factory()->for($user)->count(5)->create();
    expect($query->get($user, $user, ['longest' => 0, 'current' => 0]))
        ->toMatchArray(['level' => 2, 'rank' => 'daemon', 'next_level_xp' => 150, 'level_progress' => 0]);

    // Level 3: operator (150 - 399 XP)
    // 15 commits = 150 XP
    Commit::factory()->for($user)->count(10)->create();
    expect($query->get($user, $user, ['longest' => 0, 'current' => 0]))
        ->toMatchArray(['level' => 3, 'rank' => 'operator', 'next_level_xp' => 400, 'level_progress' => 0]);

    // Level 4: maintainer (400 - 999 XP)
    // 40 commits = 400 XP
    Commit::factory()->for($user)->count(25)->create();
    expect($query->get($user, $user, ['longest' => 0, 'current' => 0]))
        ->toMatchArray(['level' => 4, 'rank' => 'maintainer', 'next_level_xp' => 1000, 'level_progress' => 0]);

    // Level 5: wheel (1000 - 2499 XP)
    // 100 commits = 1000 XP
    Commit::factory()->for($user)->count(60)->create();
    expect($query->get($user, $user, ['longest' => 0, 'current' => 0]))
        ->toMatchArray(['level' => 5, 'rank' => 'wheel', 'next_level_xp' => 2500, 'level_progress' => 0]);

    // Level 6: root (2500+ XP)
    // 250 commits = 2500 XP
    Commit::factory()->for($user)->count(150)->create();
    expect($query->get($user, $user, ['longest' => 0, 'current' => 0]))
        ->toMatchArray(['level' => 6, 'rank' => 'root', 'next_level_xp' => null, 'level_progress' => 100]);
});

it('respects visibility scope of viewer for unapproved commits', function (): void {
    $user = User::factory()->create();
    $viewer = User::factory()->create();

    Commit::factory()->for($user)->create(['status' => CommitStatus::Pending]);

    $query = resolve(UserAchievementsQuery::class);

    // Author sees their own pending commit
    $authorResult = $query->get($user, $user);
    expect($authorResult['xp'])->toBe(25);

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

    expect($result['xp_breakdown']['streak']['count'])->toBe(42)
        ->and($result['xp_breakdown']['streak']['xp'])->toBe(42 * 15);
});

it('unlocks pastelaria badge when user has received 10 pastéis de nata on their commits', function (): void {
    $user = User::factory()->create();

    // 5 pastéis -> not unlocked yet
    Commit::factory()->for($user)->create(['pasteis_count' => 5]);

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);
    $badge = collect($result['badges'])->firstWhere('id', Achievement::Pastelaria->value);

    expect($badge)->not->toBeNull()
        ->and($badge['unlocked'])->toBeFalse()
        ->and($badge['progress'])->toBe(5)
        ->and($badge['target'])->toBe(10)
        ->and($result['xp_breakdown']['pasteis_received']['count'])->toBe(5)
        ->and($result['xp_breakdown']['pasteis_received']['xp'])->toBe(25);

    // Another commit with 5 pastéis -> total 10 -> unlocked!
    Commit::factory()->for($user)->create(['pasteis_count' => 5]);

    $result = resolve(UserAchievementsQuery::class)->get($user, $user);
    $badge = collect($result['badges'])->firstWhere('id', Achievement::Pastelaria->value);

    expect($badge)->not->toBeNull()
        ->and($badge['unlocked'])->toBeTrue()
        ->and($badge['progress'])->toBe(10)
        ->and($result['xp_breakdown']['pasteis_received']['count'])->toBe(10)
        ->and($result['xp_breakdown']['pasteis_received']['xp'])->toBe(50);
});

it('awards 5 xp per pastel received on visible commits and excludes unapproved commits for viewers', function (): void {
    $user = User::factory()->create();
    $viewer = User::factory()->create();

    Commit::factory()->for($user)->create(['pasteis_count' => 3]);
    Commit::factory()->for($user)->pending()->create(['pasteis_count' => 4]);

    $query = resolve(UserAchievementsQuery::class);

    // Author sees both approved and pending pastéis (3 + 4 = 7 pastéis * 5 = 35 XP)
    $authorResult = $query->get($user, $user);
    expect($authorResult['xp_breakdown']['pasteis_received']['count'])->toBe(7)
        ->and($authorResult['xp_breakdown']['pasteis_received']['xp'])->toBe(35);

    // Viewer only sees approved commit's pastéis (3 pastéis * 5 = 15 XP)
    $viewerResult = $query->get($user, $viewer);
    expect($viewerResult['xp_breakdown']['pasteis_received']['count'])->toBe(3)
        ->and($viewerResult['xp_breakdown']['pasteis_received']['xp'])->toBe(15);
});
