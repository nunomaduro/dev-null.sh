<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConversationMemberFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read string $conversation_id
 * @property-read string $user_id
 * @property-read int $last_read_message_id
 * @property-read bool $muted
 * @property-read bool $archived
 * @property-read Conversation $conversation
 */
#[WithoutTimestamps]
final class ConversationMember extends Model
{
    /** @use HasFactory<ConversationMemberFactory> */
    use HasFactory;

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['last_read_message_id' => 'integer', 'muted' => 'boolean', 'archived' => 'boolean'];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
