<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RemotesFrom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = fake()->unique()->userName();

        return [
            'github_id' => (string) fake()->unique()->numberBetween(1, 999_999_999),
            'username' => $username,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => 'https://avatars.githubusercontent.com/u/'.fake()->numberBetween(1, 999_999),
            'bio' => fake()->sentence(),
            'location' => fake()->city(),
            'website' => fake()->url(),
            'company' => fake()->company(),
            'has_sponsors_listing' => false,
            'preferred_lane' => null,
            'timezone' => null,
            'lane_color' => null,
            'pinned_commit_id' => null,
            'onboarded_at' => now(),
            'remember_token' => Str::random(10),
            'github_synced_at' => null,
        ];
    }

    public function notOnboarded(): self
    {
        return $this->state(fn (array $attributes): array => [
            'onboarded_at' => null,
        ]);
    }

    public function withoutProfile(): self
    {
        return $this->state(fn (array $attributes): array => [
            'email' => null,
            'avatar_url' => null,
            'bio' => null,
            'location' => null,
            'website' => null,
            'company' => null,
        ]);
    }

    /**
     * Remotes turned on. Everyone may push by default here, so tests opt into requests on purpose.
     */
    public function withRemotes(RemotesFrom $from = RemotesFrom::Everyone): self
    {
        return $this->state(fn (): array => [
            'remotes_enabled' => true,
            'remotes_from' => $from,
        ]);
    }
}
