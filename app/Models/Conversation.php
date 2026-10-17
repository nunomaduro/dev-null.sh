<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RemoteStatus;
use Carbon\CarbonInterface;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read int $id
 * @property-read string $pair
 * @property-read int|null $last_message_id
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Collection<int, ConversationParticipant> $participants
 * @property-read Collection<int, Message> $messages
 * @property-read Message|null $lastMessage
 * @property-read User $partner
 * @property-read string $partner_id
 * @property-read int $ahead
 * @property-read CarbonInterface|null $last_message_at
 * @property-read RemoteStatus $partner_status
 * @property-read int $partner_read_through_id
 * @property-read CarbonInterface|null $partner_read_at
 * @property-read CarbonInterface|null $archived_at
 */
final class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * Two people share exactly one conversation, whichever of them started it.
     */
    public static function pairOf(User $user, User $other): string
    {
        $ids = [$user->id, $other->id];
        sort($ids);

        return implode(':', $ids);
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'pair' => 'string',
            'last_message_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'partner_id' => 'string',
            'ahead' => 'integer',
            'last_message_at' => 'datetime',
            'partner_status' => RemoteStatus::class,
            'partner_read_through_id' => 'integer',
            'partner_read_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The other person, on conversations loaded for one of the two with their partner_id selected.
     *
     * @return BelongsTo<User, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }
}
