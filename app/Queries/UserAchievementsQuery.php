<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Achievement;
use App\Enums\UnixRank;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class UserAchievementsQuery
{
    public function __construct(private UserStreakQuery $streaks)
    {
        //
    }

    /**
     * @param  array{longest: int, current: int}|null  $streaks
     * @return array{
     *     level: int,
     *     rank: string,
     *     xp: int,
     *     next_level_xp: ?int,
     *     level_progress: int,
     *     xp_breakdown: array{
     *         commits: array{count: int, multiplier: int, xp: int},
     *         streak: array{count: int, multiplier: int, xp: int},
     *         stars_received: array{count: int, multiplier: int, xp: int},
     *         replies: array{count: int, multiplier: int, xp: int},
     *         stars_given: array{count: int, multiplier: int, xp: int}
     *     },
     *     ranks: list<array{
     *         rank: string,
     *         level: int,
     *         min_xp: int,
     *         description: string,
     *         achieved: bool
     *     }>,
     *     badges: list<array{
     *         id: string,
     *         name: string,
     *         description: string,
     *         icon: string,
     *         unlocked: bool,
     *         progress: int,
     *         target: int
     *     }>
     * }
     */
    public function get(User $user, ?User $viewer, ?array $streaks = null): array
    {
        $visibleCommitsQuery = $user->commits()->visibleTo($viewer);

        // Replies must be to other developers (excluding self-replies), deleted parents included
        /** @var object{total_commits: int|string, has_syntax: int|string|null, replies: int|string} $commitStats */
        $commitStats = (clone $visibleCommitsQuery)
            ->leftJoin('commits as parents', fn (JoinClause $join): JoinClause => $join
                ->on('parents.id', '=', 'commits.parent_id')
                ->where('parents.user_id', '!=', $user->id))
            ->selectRaw("COUNT(*) as total_commits, MAX(CASE WHEN commits.body LIKE '%```%' THEN 1 ELSE 0 END) as has_syntax, COUNT(parents.id) as replies")
            ->first();

        $commitsCount = (int) ($commitStats->total_commits ?? 0);
        $hasSyntaxCommit = (bool) ($commitStats->has_syntax ?? 0);
        $repliesCount = (int) ($commitStats->replies ?? 0);

        // Stars received on visible commits, excluding self-stars
        $visibleCommitIds = (clone $visibleCommitsQuery)->select('id');
        $starsReceivedCount = (int) DB::table('stars')
            ->whereIn('commit_id', $visibleCommitIds)
            ->where('user_id', '!=', $user->id)
            ->count();

        // Stars given to visible commits by other developers, excluding self-stars
        $starsGivenCount = (int) $user->starredCommits()
            ->where('commits.user_id', '!=', $user->id)
            ->where(fn (CommitBuilder $query): CommitBuilder => $query->visibleTo($viewer))
            ->count();

        $longestStreak = ($streaks ?? $this->streaks->get($user, $viewer))['longest'];
        $hasSshKey = $user->sshKeys()->exists();

        // XP breakdown: 10 per commit, 15 per streak day, 5 per star received, 5 per reply, 2 per star given
        $commitsXp = $commitsCount * 10;
        $streakXp = $longestStreak * 15;
        $starsReceivedXp = $starsReceivedCount * 5;
        $repliesXp = $repliesCount * 5;
        $starsGivenXp = $starsGivenCount * 2;

        $xp = $commitsXp + $streakXp + $starsReceivedXp + $repliesXp + $starsGivenXp;

        $currentRank = UnixRank::forXp($xp);
        $nextRank = $currentRank->next();

        $levelProgress = $nextRank instanceof UnixRank
            ? min(100, max(0, (int) round((($xp - $currentRank->minXp()) / ($nextRank->minXp() - $currentRank->minXp())) * 100)))
            : 100;

        $xpBreakdown = [
            'commits' => ['count' => $commitsCount, 'multiplier' => 10, 'xp' => $commitsXp],
            'streak' => ['count' => $longestStreak, 'multiplier' => 15, 'xp' => $streakXp],
            'stars_received' => ['count' => $starsReceivedCount, 'multiplier' => 5, 'xp' => $starsReceivedXp],
            'replies' => ['count' => $repliesCount, 'multiplier' => 5, 'xp' => $repliesXp],
            'stars_given' => ['count' => $starsGivenCount, 'multiplier' => 2, 'xp' => $starsGivenXp],
        ];

        $ranks = array_map(fn (UnixRank $rank): array => [
            'rank' => $rank->value,
            'level' => $rank->level(),
            'min_xp' => $rank->minXp(),
            'description' => $rank->description(),
            'achieved' => $xp >= $rank->minXp(),
        ], UnixRank::cases());

        $metrics = [
            Achievement::Init->value => [
                'progress' => min($commitsCount, Achievement::Init->target()),
                'target' => Achievement::Init->target(),
            ],
            Achievement::Syntax->value => [
                'progress' => $hasSyntaxCommit ? 1 : 0,
                'target' => Achievement::Syntax->target(),
            ],
            Achievement::Uptime->value => [
                'progress' => min($longestStreak, Achievement::Uptime->target()),
                'target' => Achievement::Uptime->target(),
            ],
            Achievement::Daemon->value => [
                'progress' => min($longestStreak, Achievement::Daemon->target()),
                'target' => Achievement::Daemon->target(),
            ],
            Achievement::Sysadmin->value => [
                'progress' => min($longestStreak, Achievement::Sysadmin->target()),
                'target' => Achievement::Sysadmin->target(),
            ],
            Achievement::Stdout->value => [
                'progress' => min($commitsCount, Achievement::Stdout->target()),
                'target' => Achievement::Stdout->target(),
            ],
            Achievement::KernelSpace->value => [
                'progress' => min($commitsCount, Achievement::KernelSpace->target()),
                'target' => Achievement::KernelSpace->target(),
            ],
            Achievement::Stargazer->value => [
                'progress' => min($starsGivenCount, Achievement::Stargazer->target()),
                'target' => Achievement::Stargazer->target(),
            ],
            Achievement::Superstar->value => [
                'progress' => min($starsReceivedCount, Achievement::Superstar->target()),
                'target' => Achievement::Superstar->target(),
            ],
            Achievement::Pipe->value => [
                'progress' => min($repliesCount, Achievement::Pipe->target()),
                'target' => Achievement::Pipe->target(),
            ],
            Achievement::AuthorizedKeys->value => [
                'progress' => $hasSshKey ? 1 : 0,
                'target' => Achievement::AuthorizedKeys->target(),
            ],
        ];

        $badges = array_map(function (Achievement $achievement) use ($metrics): array {
            $data = $metrics[$achievement->value];
            $progress = (int) $data['progress'];
            $target = (int) $data['target'];

            return [
                'id' => $achievement->value,
                'name' => $achievement->label(),
                'description' => $achievement->description(),
                'icon' => $achievement->icon(),
                'unlocked' => $progress >= $target,
                'progress' => $progress,
                'target' => $target,
            ];
        }, Achievement::cases());

        return [
            'level' => $currentRank->level(),
            'rank' => $currentRank->value,
            'xp' => $xp,
            'next_level_xp' => $nextRank?->minXp(),
            'level_progress' => $levelProgress,
            'xp_breakdown' => $xpBreakdown,
            'ranks' => $ranks,
            'badges' => $badges,
        ];
    }
}
