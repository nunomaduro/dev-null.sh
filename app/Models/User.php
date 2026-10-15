<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property-read string $id
 * @property-read string $github_id
 * @property-read string $username
 * @property-read string $name
 * @property-read string|null $email
 * @property-read string|null $avatar_url
 * @property-read string|null $bio
 * @property-read string|null $location
 * @property-read string|null $website
 * @property-read string|null $company
 * @property-read int|null $preferred_lane
 * @property-read string|null $pinned_commit_id
 * @property-read string|null $remember_token
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Collection<int, Commit> $commits
 * @property-read Collection<int, Commit> $starredCommits
 * @property-read Collection<int, User> $following
 * @property-read Collection<int, User> $followers
 * @property-read Collection<int, Notification> $notifications
 * @property-read Collection<int, SshKey> $sshKeys
 * @property-read int $commits_count
 * @property-read int $following_count
 * @property-read int $followers_count
 */
#[Hidden([
    'github_id',
    'email',
    'remember_token',
    'preferred_lane',
    'pinned_commit_id',
])]
final class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'github_id' => 'string',
            'username' => 'string',
            'name' => 'string',
            'email' => 'string',
            'avatar_url' => 'string',
            'bio' => 'string',
            'location' => 'string',
            'website' => 'string',
            'company' => 'string',
            'preferred_lane' => 'integer',
            'pinned_commit_id' => 'string',
            'remember_token' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Commit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(Commit::class);
    }

    /**
     * @return HasMany<SshKey, $this>
     */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    /**
     * @return BelongsToMany<Commit, $this>
     */
    public function starredCommits(): BelongsToMany
    {
        return $this->belongsToMany(Commit::class, 'stars')->withTimestamps();
    }

    public function hasStarred(Commit $commit): bool
    {
        return $this->starredCommits()->whereKey($commit->id)->exists();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'followed_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'followed_id', 'follower_id')->withTimestamps();
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_id');
    }

    /**
     * Users sign in with GitHub, so they have no password.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function isAdmin(): bool
    {
        /** @var list<string> $admins */
        $admins = config('app.admins');

        return in_array(mb_strtolower($this->username), array_map(mb_strtolower(...), $admins), true);
    }

    /**
     * Unlike loadCount(), counts follows on the pivot table alone: the self-referencing
     * relations would otherwise join the users table for every follow. Commits are
     * counted as the viewer sees them, which is all of them for the user themselves.
     */
    public function loadCounts(?self $viewer = null): self
    {
        return $this->fillCounts($this->commits()->visibleTo($viewer ?? $this)->count());
    }

    /**
     * Counts what anyone may see about the user, so only approved commits.
     */
    public function loadPublicCounts(): self
    {
        return $this->fillCounts($this->commits()->approved()->count());
    }

    /**
     * Changes whenever anything drawn on the profile's link preview image may have changed.
     */
    public function openGraphImageVersion(): string
    {
        return hash('xxh3', implode(':', [
            $this->updated_at->getTimestamp(),
            $this->commits_count,
            $this->following_count,
            $this->followers_count,
        ]));
    }

    /**
     * How link previews title the user, without repeating a name that is just the username.
     */
    public function nameWithHandle(): string
    {
        return $this->name === $this->username ? "@{$this->username}" : "{$this->name} (@{$this->username})";
    }

    public function isFollowing(self $user): bool
    {
        return $this->following()->newPivotQuery()->where('followed_id', $user->id)->exists();
    }

    public function lane(): int
    {
        return $this->preferred_lane ?? $this->automaticLane();
    }

    /**
     * The same hash of the id the frontend used before lanes could be chosen, so nobody's color changes on its own.
     */
    public function automaticLane(): int
    {
        $hash = 0;

        foreach (mb_str_split($this->id) as $character) {
            $hash = ($hash * 31 + mb_ord($character)) & 0xFFFFFFFF;
        }

        return $hash % 6;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'lane' => $this->lane(),
        ];
    }

    private function fillCounts(int $commits): self
    {
        $counts = [
            'commits_count' => $commits,
            'followers_count' => $this->followers()->newPivotQuery()->count(),
            'following_count' => $this->following()->newPivotQuery()->count(),
        ];

        $this->forceFill($counts)->syncOriginalAttributes(array_keys($counts));

        return $this;
    }
}
