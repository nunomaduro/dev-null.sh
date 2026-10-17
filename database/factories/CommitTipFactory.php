<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommitGift;
use App\Models\Commit;
use App\Models\CommitTip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommitTip>
 */
final class CommitTipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'recipient_id' => User::factory(),
            'commit_id' => Commit::factory(),
            'amount' => 10,
            'gift_type' => CommitGift::Coffee,
            'message' => 'Great commit!',
        ];
    }
}
