<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read User $firstUser
 * @property-read User $secondUser
 * @property-read Collection<int, ConversationMember> $members
 * @property-read DirectMessage|null $latestMessage
 * @property-read string $id
 * @property-read string $first_user_id
 * @property-read string $second_user_id
 * @property-read string $initiator_id
 * @property-read string $status
 * @property-read CarbonInterface $last_message_at
 */
final class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    use HasUuids;

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** @return HasMany<DirectMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class);
    }

    /** @return HasMany<ConversationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    /** @return BelongsTo<User, $this> */
    public function firstUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function secondUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_user_id');
    }

    /** @return HasOne<DirectMessage, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(DirectMessage::class)->latestOfMany();
    }

    public function includes(User $user): bool
    {
        return in_array($user->id, [$this->first_user_id, $this->second_user_id], true);
    }

    public function otherId(User $user): string
    {
        return $this->first_user_id === $user->id ? $this->second_user_id : $this->first_user_id;
    }
}
