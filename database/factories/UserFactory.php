<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LivePlatform;
use App\Enums\ModerationReason;
use App\Enums\RemotesFrom;
use App\Models\Ban;
use App\Models\User;
use Carbon\CarbonInterface;
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
            'commit_rules_accepted_at' => now(),
            'remember_token' => Str::random(10),
            'github_synced_at' => null,
        ];
    }

    /**
     * Someone who never posted, and so hasn't read the commit rules yet.
     */
    public function firstTimePoster(): self
    {
        return $this->state(fn (array $attributes): array => [
            'commit_rules_accepted_at' => null,
        ]);
    }

    /**
     * Banned the way moderators ban: by the GitHub account, and on the user.
     */
    public function banned(ModerationReason $reason = ModerationReason::Spam): self
    {
        return $this->state(fn (array $attributes): array => [
            'banned_at' => now(),
        ])->afterCreating(function (User $user) use ($reason): void {
            Ban::factory()->create(['github_id' => $user->github_id, 'reason' => $reason]);
        });
    }

    /**
     * Streaming until the given time, two hours from now by default, the way going live stores it.
     */
    public function live(LivePlatform $platform = LivePlatform::YouTube, ?CarbonInterface $until = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'live_platform' => $platform,
            'live_url' => match ($platform) {
                LivePlatform::YouTube => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                LivePlatform::Twitch => 'https://www.twitch.tv/octocat',
            },
            'live_ends_at' => $until ?? now()->addHours(2),
        ]);
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
