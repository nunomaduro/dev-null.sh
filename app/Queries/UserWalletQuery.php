<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\DailyEpochClaim;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

final readonly class UserWalletQuery
{
    /**
     * @return array{
     *     balance: int,
     *     lifetime_earned: int,
     *     lifetime_tipped: int,
     *     has_claimed_today: bool,
     *     today_allocated: ?int
     * }
     */
    public function get(User $user): array
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->where('user_id', $user->id)->first();

        $today = CarbonImmutable::today()->toDateString();

        /** @var DailyEpochClaim|null $todayClaim */
        $todayClaim = DailyEpochClaim::query()
            ->where('user_id', $user->id)
            ->where('epoch_date', $today)
            ->first();

        return [
            'balance' => $wallet instanceof Wallet ? $wallet->balance : 0,
            'lifetime_earned' => $wallet instanceof Wallet ? $wallet->lifetime_earned : 0,
            'lifetime_tipped' => $wallet instanceof Wallet ? $wallet->lifetime_tipped : 0,
            'has_claimed_today' => $todayClaim !== null && $todayClaim->claimed_at !== null,
            'today_allocated' => $todayClaim?->allocated_bytes,
        ];
    }
}
