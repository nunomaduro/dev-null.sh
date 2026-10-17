<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Achievement;
use App\Enums\CommitStatus;
use App\Enums\UnixRank;
use App\Models\Commit;
use App\Models\LevelRewardClaim;
use App\Models\User;
use App\Support\LevelProgression;
use App\Support\LevelRewards;
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
     *         stars_given: array{count: int, multiplier: int, xp: int},
     *         kudos_given: array{count: int, multiplier: int, xp: int},
     *         kudos_received: array{count: int, multiplier: int, xp: int},
     *         skins_unlocked: array{count: int, multiplier: int, xp: int}
     *     },
     *     level_rewards: list<array{level: int, bytes: int, skin: ?string, claimed: bool, eligible: bool}>,
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

        // Count distinct people, not tip transactions: repeatedly gifting the same
        // person never manufactures additional rank XP. Only approved, live
        // commits qualify, so moderation also applies to social progression.
        $eligibleTips = DB::table('commit_tips')
            ->join('commits', 'commits.id', '=', 'commit_tips.commit_id')
            ->where('commits.status', CommitStatus::Approved->value)
            ->whereNull('commits.deleted_at');

        $kudosGivenCount = (int) (clone $eligibleTips)
            ->where('commit_tips.sender_id', $user->id)
            ->distinct()
            ->count('commit_tips.recipient_id');

        $kudosReceivedCount = (int) (clone $eligibleTips)
            ->where('commit_tips.recipient_id', $user->id)
            ->distinct()
            ->count('commit_tips.sender_id');

        // Cosmetic unlocks are permanent, one-time XP milestones. Equipping
        // another skin or spending the same Bytes again cannot farm XP.
        $skinsUnlockedCount = count(array_intersect(
            $user->shadow->unlocked_skins ?? [],
            ['amber-crt', 'matrix', 'cyber-cyan', 'kernel-red'],
        ));

        // Public, moderated activity drives XP, even for the author's own page.
        // Only the first three approved commits per day earn contribution XP.
        $longestStreak = $this->streaks->get($user, null)['longest'];
        $badgeLongestStreak = ($streaks ?? $this->streaks->get($user, $viewer))['longest'];
        $rewardedCommitsCount = (int) $user->commits()
            ->approved()
            ->selectRaw('date(created_at) as day, count(*) as daily_count')
            ->groupByRaw('date(created_at)')
            ->pluck('daily_count')
            ->sum(fn (mixed $count): int => is_numeric($count) ? min(3, (int) $count) : 0);
        $hasSshKey = $user->sshKeys()->exists();

        // XP breakdown: 10 per commit, 15 per streak day, 5 per star received, 5 per reply, 2 per star given
        $commitsXp = $rewardedCommitsCount * 10;
        $streakXp = $longestStreak * 15;
        $approvedStarsReceived = (int) DB::table('stars')
            ->whereIn('commit_id', $user->commits()->approved()->select('id'))
            ->where('user_id', '!=', $user->id)
            ->count();
        $approvedReplies = $user->commits()->approved()
            ->whereNotNull('parent_id')
            ->whereIn('parent_id', Commit::query()->withTrashed()->where('user_id', '!=', $user->id)->select('id'))
            ->count();
        $approvedStarsGiven = $user->starredCommits()
            ->where('commits.user_id', '!=', $user->id)
            ->where(fn (CommitBuilder $query): CommitBuilder => $query->approved())
            ->count();
        $starsReceivedXp = $approvedStarsReceived * 5;
        $repliesXp = $approvedReplies * 5;
        $starsGivenXp = $approvedStarsGiven * 2;
        $kudosGivenXp = $kudosGivenCount * 5;
        $kudosReceivedXp = $kudosReceivedCount * 5;
        $skinsUnlockedXp = $skinsUnlockedCount * 10;

        $xp = $commitsXp + $streakXp + $starsReceivedXp + $repliesXp + $starsGivenXp
            + $kudosGivenXp + $kudosReceivedXp + $skinsUnlockedXp;

        [$level, $nextLevelXp, $levelProgress] = LevelProgression::progress($xp);
        $currentRank = LevelProgression::rankForLevel($level);

        $xpBreakdown = [
            'commits' => ['count' => $rewardedCommitsCount, 'multiplier' => 10, 'xp' => $commitsXp],
            'streak' => ['count' => $longestStreak, 'multiplier' => 15, 'xp' => $streakXp],
            'stars_received' => ['count' => $approvedStarsReceived, 'multiplier' => 5, 'xp' => $starsReceivedXp],
            'replies' => ['count' => $approvedReplies, 'multiplier' => 5, 'xp' => $repliesXp],
            'stars_given' => ['count' => $approvedStarsGiven, 'multiplier' => 2, 'xp' => $starsGivenXp],
            'kudos_given' => ['count' => $kudosGivenCount, 'multiplier' => 5, 'xp' => $kudosGivenXp],
            'kudos_received' => ['count' => $kudosReceivedCount, 'multiplier' => 5, 'xp' => $kudosReceivedXp],
            'skins_unlocked' => ['count' => $skinsUnlockedCount, 'multiplier' => 10, 'xp' => $skinsUnlockedXp],
        ];

        $ranks = array_map(fn (UnixRank $rank): array => [
            'rank' => $rank->value,
            'level' => $rank->minLevel(),
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
                'progress' => min($badgeLongestStreak, Achievement::Uptime->target()),
                'target' => Achievement::Uptime->target(),
            ],
            Achievement::Daemon->value => [
                'progress' => min($badgeLongestStreak, Achievement::Daemon->target()),
                'target' => Achievement::Daemon->target(),
            ],
            Achievement::Sysadmin->value => [
                'progress' => min($badgeLongestStreak, Achievement::Sysadmin->target()),
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

        $claimedRewardLevels = LevelRewardClaim::query()
            ->where('user_id', $user->id)
            ->pluck('level')
            ->map(fn (mixed $claimed): int => is_numeric($claimed) ? (int) $claimed : 0)
            ->all();

        $levelRewards = [];
        foreach (LevelRewards::MILESTONES as $milestone => $reward) {
            $levelRewards[] = [
                'level' => $milestone,
                'bytes' => $reward['bytes'],
                'skin' => $reward['skin'],
                'claimed' => in_array($milestone, $claimedRewardLevels, true),
                'eligible' => $level >= $milestone,
            ];
        }

        return [
            'level' => $level,
            'rank' => $currentRank->value,
            'xp' => $xp,
            'next_level_xp' => $nextLevelXp,
            'level_progress' => $levelProgress,
            'xp_breakdown' => $xpBreakdown,
            'level_rewards' => $levelRewards,
            'ranks' => $ranks,
            'badges' => $badges,
        ];
    }
}
