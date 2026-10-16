<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
final class ConversationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $ids = [User::factory()->create()->id, User::factory()->create()->id];
        sort($ids);

        return ['first_user_id' => $ids[0], 'second_user_id' => $ids[1], 'initiator_id' => $ids[0], 'status' => 'accepted', 'last_message_at' => now()];
    }
}
