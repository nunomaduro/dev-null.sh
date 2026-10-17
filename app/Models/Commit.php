<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommitStatus;
use App\Queries\CommitBuilder;
use App\Services\CommitBodies;
use Carbon\CarbonInterface;
use Database\Factories\CommitFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read string $id
 * @property-read string|null $user_id
 * @property-read string|null $parent_id
 * @property-read string|null $root_id
 * @property-read float $hot_score
 * @property-read string $body
 * @property-read CommitStatus $status
 * @property-read string|null $link_preview_id
 * @property-read string|null $quoted_commit_id
 * @property-read CommitStatus|null $ai_decision
 * @property-read list<string>|null $ai_categories
 * @property-read list<float>|null $embedding
 * @property-read string|null $giphy_id
 * @property-read string|null $giphy_url
 * @property-read string|null $giphy_page_url
 * @property-read string|null $giphy_title
 * @property-read string|null $giphy_username
 * @property-read int|null $giphy_width
 * @property-read int|null $giphy_height
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read CarbonInterface|null $deleted_at
 * @property-read int $stars_count
 * @property-read int $pasteis_count
 * @property-read bool $starred
 * @property-read User|null $user
 * @property-read Collection<int, User> $stars
 * @property-read Commit|null $parent
 * @property-read Collection<int, Commit> $replies
 * @property-read int $replies_count
 * @property-read int|null $repliers_count
 * @property-read bool $is_saved
 * @property-read LinkPreview|null $linkPreview
 * @property-read Poll|null $poll
 * @property-read Commit|null $quotedCommit
 * @property-read Collection<int, Commit> $quotes
 * @property-read Collection<int, CommitImage> $images
 */
#[Hidden([
    'ai_decision',
    'ai_categories',
    'embedding',
    'giphy_id',
    'giphy_url',
    'giphy_page_url',
    'giphy_title',
    'giphy_username',
    'giphy_width',
    'giphy_height',
])]
#[UseEloquentBuilder(CommitBuilder::class)]
final class Commit extends Model
{
    /** @use HasFactory<CommitFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const int RESTORABLE_SECONDS = 30;

    public const int MIN_BODY_LENGTH = 3;

    public const int MAX_BODY_LENGTH = 4000;

    /**
     * Lists show a longer body folded to its first characters and lines, the rest one click away.
     */
    public const int PREVIEW_LENGTH = 280;

    public const int PREVIEW_LINES = 8;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pasteis_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'user_id' => 'string',
            'parent_id' => 'string',
            'root_id' => 'string',
            'hot_score' => 'float',
            'pasteis_count' => 'integer',
            'body' => 'string',
            'status' => CommitStatus::class,
            'link_preview_id' => 'string',
            'quoted_commit_id' => 'string',
            'ai_decision' => CommitStatus::class,
            'ai_categories' => 'array',
            'embedding' => AsVector::class,
            'giphy_id' => 'string',
            'giphy_url' => 'string',
            'giphy_page_url' => 'string',
            'giphy_title' => 'string',
            'giphy_username' => 'string',
            'giphy_width' => 'integer',
            'giphy_height' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * A deleted commit only ever leaves a tombstone behind: never its author or body. Otherwise, the body also comes split
     * into segments, for rendering.
     *
     * @return array<mixed>
     */
    public function toArray(): array
    {
        if ($this->trashed()) {
            $keys = ['id', 'parent_id', 'deleted_at', 'replies_count'];

            return array_intersect_key($this->attributesToArray(), array_flip($keys));
        }

        return [...parent::toArray(), 'gif' => $this->gif(), ...resolve(CommitBodies::class)->segments($this->body)];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function stars(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'stars')->withTimestamps();
    }

    /**
     * Approved commits are visible to everyone but people across a block. Authors can also see their own.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user instanceof User && $this->user_id === $user->id) {
            return true;
        }

        return $this->status === CommitStatus::Approved
            && (! $user instanceof User || ! $this->user instanceof User || ! $user->hasBlockWith($this->user));
    }

    public function isRestorable(): bool
    {
        return $this->user_id !== null && $this->deleted_at !== null && now()->lessThan($this->deleted_at->copy()->addSeconds(self::RESTORABLE_SECONDS));
    }

    /**
     * @return BelongsTo<Commit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The top-level commit that started the thread the commit belongs to, which is the commit itself when it is top-level.
     */
    public function threadRootId(): string
    {
        return $this->root_id ?? $this->id;
    }

    /**
     * @return HasMany<Commit, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Bookmark, $this>
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    /**
     * @return BelongsTo<LinkPreview, $this>
     */
    public function linkPreview(): BelongsTo
    {
        return $this->belongsTo(LinkPreview::class);
    }

    /**
     * @return HasOne<Poll, $this>
     */
    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class);
    }

    /**
     * @return BelongsTo<Commit, $this>
     */
    public function quotedCommit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_commit_id');
    }

    /**
     * @return HasMany<Commit, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(self::class, 'quoted_commit_id');
    }

    /**
     * @return HasMany<CommitImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(CommitImage::class)->orderBy('position');
    }

    /**
     * Images follow the commit's visibility, and moderators see them to review the commit.
     */
    public function imagesAreVisibleTo(?User $user): bool
    {
        if ($this->trashed()) {
            return false;
        }

        if ($this->status === CommitStatus::Approved) {
            return true;
        }

        return $user instanceof User && ($this->user_id === $user->id || $user->isAdmin());
    }

    /**
     * The attached GIPHY GIF, hotlinked by its media URL exactly as GIPHY's API returned it.
     *
     * @return array{id: string, url: string, page_url: string, title: string|null, username: string|null, width: int|null, height: int|null}|null
     */
    public function gif(): ?array
    {
        if ($this->giphy_id === null || $this->giphy_url === null || $this->giphy_page_url === null) {
            return null;
        }

        return [
            'id' => $this->giphy_id,
            'url' => $this->giphy_url,
            'page_url' => $this->giphy_page_url,
            'title' => $this->giphy_title,
            'username' => $this->giphy_username,
            'width' => $this->giphy_width,
            'height' => $this->giphy_height,
        ];
    }

    /**
     * Loads what anyone may see around the commit: its author, its approved replies, and its parent only when approved.
     */
    public function loadPublicPreview(): self
    {
        $this->forceFill([
            'replies_count' => self::query()->withTrashed()->withVisibleReplyCount(null)
                ->whereKey($this->getAttributeFromArray($this->getKeyName()))->first()->replies_count ?? 0,
        ])->syncOriginalAttribute('replies_count');

        return $this->load(['user', 'parent' => fn (BelongsTo $query): BelongsTo => $query->where('status', CommitStatus::Approved)->with('user')]);
    }

    /**
     * Changes whenever anything drawn on the commit's link preview image may have changed.
     */
    public function openGraphImageVersion(): string
    {
        return hash('xxh3', implode(':', [
            $this->updated_at->getTimestamp(),
            $this->replies_count,
            $this->user?->updated_at->getTimestamp(),
            $this->parent?->user?->username,
        ]));
    }
}
