<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CommitGift;
use App\Enums\NotificationType;
use App\Models\Commit;
use App\Models\CommitTip;
use App\Models\Notification;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateCommitTip
{
    /**
     * Tips a commit with Byte tokens and creates an attached gift.
     */
    public function handle(User $sender, Commit $commit, CommitGift $gift, ?string $message = null): ?CommitTip
    {
        if ($commit->user_id === null || $commit->user_id === $sender->id) {
            return null;
        }

        $cost = $gift->cost();

        return DB::transaction(function () use ($sender, $commit, $gift, $cost, $message): ?CommitTip {
            /** @var Wallet $senderWallet */
            $senderWallet = Wallet::query()->firstOrCreate(
                ['user_id' => $sender->id],
                ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_tipped' => 0]
            );

            // A conditional SQL update prevents concurrent tips from spending the same Bytes twice.
            if (Wallet::query()->whereKey($senderWallet->id)->where('balance', '>=', $cost)->decrement('balance', $cost) === 0) {
                return null;
            }

            /** @var Wallet $recipientWallet */
            $recipientWallet = Wallet::query()->firstOrCreate(
                ['user_id' => $commit->user_id],
                ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_tipped' => 0]
            );

            $senderWallet->increment('lifetime_tipped', $cost);

            $recipientWallet->increment('balance', $cost);
            $recipientWallet->increment('lifetime_earned', $cost);

            /** @var CommitTip $tip */
            $tip = CommitTip::query()->create([
                'sender_id' => $sender->id,
                'recipient_id' => $commit->user_id,
                'commit_id' => $commit->id,
                'amount' => $cost,
                'gift_type' => $gift,
                'message' => $message !== null && $message !== '' ? Str::limit($message, 140, '') : null,
            ]);

            Notification::query()->insertOrIgnore([
                'id' => Str::uuid7()->toString(),
                'recipient_id' => $commit->user_id,
                'actor_id' => $sender->id,
                'type' => NotificationType::Tipped->value,
                'commit_id' => $commit->id,
                'subject_id' => $commit->id,
                'key' => 'tipped:'.$tip->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $tip;
        });
    }
}
