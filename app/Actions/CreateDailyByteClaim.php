<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\DailyEpochClaim;
use App\Models\User;
use App\Models\Wallet;
use App\Queries\UserStreakQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CreateDailyByteClaim
{
    public function __construct(private UserStreakQuery $userStreakQuery) {}

    /**
     * Calculates and claims daily Byte tokens for the given user.
     */
    public function handle(User $user): DailyEpochClaim
    {
        $today = CarbonImmutable::today();
        $dateString = $today->toDateString();

        return DB::transaction(function () use ($user, $today, $dateString): DailyEpochClaim {
            // Compute activity score
            $todayCommitsCount = $user->commits()
                ->approved()
                ->whereDate('created_at', $today)
                ->count();

            $todayQuotesCount = $user->commits()
                ->approved()
                ->whereDate('created_at', $today)
                ->whereNotNull('quoted_commit_id')
                ->count();

            $streakData = $this->userStreakQuery->get($user, null);
            $currentStreak = $streakData['current'];

            // Streak multiplier: 1.0 (default), 1.25 (7+ days), 1.5 (30+ days), 2.0 (100+ days)
            $streakMultiplier = match (true) {
                $currentStreak >= 100 => 2.0,
                $currentStreak >= 30 => 1.5,
                $currentStreak >= 7 => 1.25,
                default => 1.0,
            };

            // Tenure multiplier based on account age
            $accountDays = (int) $user->created_at->diffInDays($today);
            $tenureMultiplier = match (true) {
                $accountDays >= 365 => 1.5,
                $accountDays >= 180 => 1.25,
                $accountDays >= 30 => 1.1,
                default => 1.0,
            };

            $baseActivityScore = max(10, ($todayCommitsCount * 10) + ($todayQuotesCount * 5));
            $totalScore = (int) round($baseActivityScore * $streakMultiplier * $tenureMultiplier);

            // Minimum 10 bytes guaranteed on login, up to 100 bytes max per user daily
            $allocatedBytes = min(100, max(10, (int) round($totalScore / 2)));

            // The unique (user_id, epoch_date) constraint makes claiming idempotent,
            // even when two requests arrive at the same time.
            /** @var DailyEpochClaim $claim */
            $claim = DailyEpochClaim::query()->firstOrCreate(
                ['user_id' => $user->id, 'epoch_date' => $dateString],
                [
                    'activity_score' => $totalScore,
                    'allocated_bytes' => $allocatedBytes,
                    'claimed_at' => now(),
                    'expires_at' => $today->endOfDay(),
                ]
            );

            if (! $claim->wasRecentlyCreated) {
                return $claim;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_tipped' => 0]
            );

            $wallet->increment('balance', $allocatedBytes);
            $wallet->increment('lifetime_earned', $allocatedBytes);

            return $claim;
        });
    }
}
