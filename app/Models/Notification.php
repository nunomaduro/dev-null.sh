<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationType;
use App\Queries\NotificationBuilder;
use Carbon\CarbonInterface;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $recipient_id
 * @property-read string $actor_id
 * @property-read NotificationType $type
 * @property-read string|null $commit_id
 * @property-read string|null $subject_id
 * @property-read string $key
 * @property-read string|null $reason
 * @property-read CarbonInterface|null $read_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $recipient
 * @property-read User $actor
 * @property-read Commit|null $commit
 * @property-read string $group_id
 * @property-read int $event_count
 * @property-read int $actors_count
 * @property-read int $unread_count
 * @property-read string|null $second_actor_id
 * @property-read string|null $third_actor_id
 * @property-read Collection<int, User> $actors
 */
#[Hidden([
    'key',
    'group_rank',
    'actor_position',
    'second_actor_id',
    'third_actor_id',
])]
#[UseEloquentBuilder(NotificationBuilder::class)]
final class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'recipient_id' => 'string',
            'actor_id' => 'string',
            'type' => NotificationType::class,
            'commit_id' => 'string',
            'subject_id' => 'string',
            'key' => 'string',
            'reason' => 'string',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'actors_count' => 'integer',
            'unread_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Commit, $this>
     */
    public function commit(): BelongsTo
    {
        return $this->belongsTo(Commit::class);
    }
}
