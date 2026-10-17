<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LevelRewardClaim;
use App\Models\User;
use App\Models\Wallet;
use App\Queries\UserAchievementsQuery;
use App\Support\LevelRewards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateLevelRewardClaim
{
    public function __construct(
        private UserAchievementsQuery $achievements,
        private UpdateShadowRewardSkin $skins,
    ) {}

    /**
     * Unique insertion and credit are atomic; retries cannot double-award.
     */
    public function handle(User $user, int $level): bool
    {
        $reward = LevelRewards::MILESTONES[$level] ?? null;

        if ($reward === null || $this->achievements->get($user, null)['level'] < $level) {
            return false;
        }

        return DB::transaction(function () use ($user, $level, $reward): bool {
            $inserted = LevelRewardClaim::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'level' => $level,
                'bytes' => $reward['bytes'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], [
                'balance' => 0,
                'lifetime_earned' => 0,
                'lifetime_tipped' => 0,
            ]);

            $wallet->increment('balance', $reward['bytes']);
            $wallet->increment('lifetime_earned', $reward['bytes']);

            $this->skins->handle($user, $reward['skin']);

            return true;
        });
    }
}
