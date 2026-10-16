<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationMember> */
final class ConversationMemberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['conversation_id' => Conversation::factory(), 'user_id' => fn (array $attributes): string => Conversation::query()->whereKey($attributes['conversation_id'])->sole()->first_user_id];
    }
}
