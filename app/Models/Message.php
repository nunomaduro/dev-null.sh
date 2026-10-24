<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgEvent;
use App\Services\CodeSpans;
use Carbon\CarbonInterface;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Bodies, and the people they mention, are encrypted at rest with the app key, so database dumps and backups don't
 * expose them. Rotating the key needs the old one in APP_PREVIOUS_KEYS, or older messages can no longer be read.
 *
 * @property-read int $id
 * @property-read string $conversation_id
 * @property-read string $user_id
 * @property-read string $client_id
 * @property-read string $body
 * @property-read int|null $reply_to_id
 * @property-read array<string, string>|null $mentions
 * @property-read string|null $link_preview_id
 * @property-read OrgEvent|null $event
 * @property-read string|null $subject_id
 * @property-read int $reactions_version
 * @property-read CarbonInterface|null $pinned_at
 * @property-read User $user
 * @property-read User|null $subject
 * @property-read Message|null $replyTo
 * @property-read LinkPreview|null $linkPreview
 * @property-read Collection<int, MessageImage> $images
 * @property-read MessageVoice|null $voice
 * @property-read Collection<int, MessageReaction> $reactions
 * @property-read Conversation $conversation
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Visible(['id', 'user_id', 'client_id', 'body', 'reply_to_id', 'mentions', 'event', 'subject_id', 'pinned_at', 'created_at', 'user', 'subject', 'linkPreview', 'images', 'voice', 'reactions'])]
final class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const int MAX_LENGTH = 2000;

    /**
     * What a message is shown with in a thread: its link card, its images, its voice note, its reactions, and the
     * message it replies to.
     */
    public const array SHOWN_WITH = ['linkPreview', 'images', 'voice', 'reactions', 'replyTo.images', 'replyTo.voice'];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'conversation_id' => 'string',
            'user_id' => 'string',
            'client_id' => 'string',
            'body' => 'encrypted',
            'reply_to_id' => 'integer',
            'mentions' => 'encrypted:array',
            'link_preview_id' => 'string',
            'event' => OrgEvent::class,
            'subject_id' => 'string',
            'reactions_version' => 'integer',
            'pinned_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The person an org event is about, like the one added or removed.
     *
     * @return BelongsTo<User, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /**
     * @return BelongsTo<LinkPreview, $this>
     */
    public function linkPreview(): BelongsTo
    {
        return $this->belongsTo(LinkPreview::class);
    }

    /**
     * @return HasMany<MessageImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(MessageImage::class)->orderBy('position');
    }

    /**
     * @return HasOne<MessageVoice, $this>
     */
    public function voice(): HasOne
    {
        return $this->hasOne(MessageVoice::class);
    }

    /**
     * In the order they were left, so a reaction's chip keeps its place.
     *
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class)->orderBy('id');
    }

    /**
     * Only the people in the conversation see a message, and in an org only from where their history starts, unless
     * the org pinned it for everyone.
     */
    public function isVisibleTo(User $user): bool
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $this->conversation_id)
            ->where('user_id', $user->id)
            ->when($this->pinned_at === null, fn (Builder $query): Builder => $query->where('visible_from_id', '<', $this->id))
            ->exists();
    }

    /**
     * The body also comes split into segments, for rendering, and the message it replies to only as much as its
     * ghost shows: who wrote it, its body, and whether it carried images or a voice note.
     *
     * @return array<mixed>
     */
    public function toArray(): array
    {
        $message = [...parent::toArray(), 'body_segments' => resolve(CodeSpans::class)->highlight($this->body)];

        if ($this->relationLoaded('replyTo')) {
            $message['reply_to'] = $this->replyTo instanceof self ? [
                'id' => $this->replyTo->id,
                'user_id' => $this->replyTo->user_id,
                'body' => $this->replyTo->body,
                'images_count' => $this->replyTo->images->count(),
                'has_voice' => $this->replyTo->voice instanceof MessageVoice,
            ] : null;
        }

        return $message;
    }
}
