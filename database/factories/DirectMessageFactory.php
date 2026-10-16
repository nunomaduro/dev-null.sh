<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\DirectMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DirectMessage> */
final class DirectMessageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['conversation_id' => Conversation::factory(), 'sender_id' => fn (array $attributes): string => Conversation::query()->whereKey($attributes['conversation_id'])->sole()->first_user_id, 'client_id' => Str::uuid()->toString(), 'body' => fake()->sentence(), 'created_at' => now()];
    }
}
