<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
final class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'client_id' => Str::uuid()->toString(),
            'body' => fake()->sentence(),
        ];
    }

    public function pinned(): self
    {
        return $this->state(fn (array $attributes): array => [
            'pinned_at' => now(),
        ]);
    }
}
