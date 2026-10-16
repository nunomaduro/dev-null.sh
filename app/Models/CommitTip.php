<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommitGift;
use Carbon\CarbonInterface;
use Database\Factories\CommitTipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $sender_id
 * @property-read string $recipient_id
 * @property-read string $commit_id
 * @property-read int $amount
 * @property-read CommitGift $gift_type
 * @property-read string|null $message
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read User $sender
 * @property-read User $recipient
 * @property-read Commit $commit
 */
final class CommitTip extends Model
{
    /** @use HasFactory<CommitTipFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'sender_id' => 'string',
            'recipient_id' => 'string',
            'commit_id' => 'string',
            'amount' => 'integer',
            'gift_type' => CommitGift::class,
            'message' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /**
     * @return BelongsTo<Commit, $this>
     */
    public function commit(): BelongsTo
    {
        return $this->belongsTo(Commit::class);
    }
}
