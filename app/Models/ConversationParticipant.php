<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RemoteStatus;
use Carbon\CarbonInterface;
use Database\Factories\ConversationParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read int $id
 * @property-read int $conversation_id
 * @property-read string $user_id
 * @property-read RemoteStatus $status
 * @property-read CarbonInterface|null $last_message_at
 * @property-read int $read_through_id
 * @property-read CarbonInterface|null $read_at
 * @property-read CarbonInterface|null $archived_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class ConversationParticipant extends Model
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'read_through_id' => 0,
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'conversation_id' => 'integer',
            'user_id' => 'string',
            'status' => RemoteStatus::class,
            'last_message_at' => 'datetime',
            'read_through_id' => 'integer',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
