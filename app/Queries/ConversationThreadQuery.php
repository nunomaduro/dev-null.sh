<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\UpdateConversationTyping;
use App\Enums\OrgRole;
use App\Enums\PushAccess;
use App\Enums\RemoteStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * @phpstan-type ReactionChange array{id: int, reactions: EloquentCollection<int, MessageReaction>}
 * @phpstan-type DirectThread array{
 *     messages: Collection<int, Message>,
 *     has_older: bool,
 *     reactions_version: int,
 *     reaction_changes: Collection<int, ReactionChange>,
 *     read_through_id: int,
 *     partner_read_through_id: int,
 *     partner_read_at: string|null,
 *     partner_typing: bool,
 *     is_archived: bool,
 *     pinned_at: string|null,
 *     muted_until_at: string|null,
 *     access: PushAccess
 * }
 * @phpstan-type OrgThread array{
 *     messages: Collection<int, Message>,
 *     has_older: bool,
 *     reactions_version: int,
 *     reaction_changes: Collection<int, ReactionChange>,
 *     pinned: Collection<int, Message>|null,
 *     read_through_id: int,
 *     members: Collection<int, array{user: User, role: OrgRole|null, read_through_id: int, suggests_mentions: bool, is_followed: bool}>,
 *     typing: array<int, string>,
 *     is_archived: bool,
 *     pinned_at: string|null,
 *     muted_until_at: string|null,
 *     role: OrgRole|null,
 *     access: PushAccess
 * }
 */
final readonly class ConversationThreadQuery
{
    public const int PAGE = 50;

    public function __construct(private PinnedMessagesQuery $pinnedMessages)
    {
        //
    }

    /**
     * A one-to-one conversation. Reads only show as pulled when both people share them, and only once the partner
     * has merged the conversation.
     *
     * @return DirectThread
     */
    public function get(User $user, User $partner, ?int $after = null, ?int $before = null, ?int $reactionsAfter = null): array
    {
        $conversation = Conversation::between($user, $partner);

        $participants = $conversation instanceof Conversation
            ? $conversation->participants()->get()->keyBy('user_id')
            : new Collection();
        $mine = $participants->get($user->id);
        $theirs = $participants->get($partner->id);
        $access = PushAccessQuery::of($user, $partner, $mine, $theirs);
        $sharesReads = $user->pull_receipts && $partner->pull_receipts && $theirs?->status === RemoteStatus::Open;
        [$messages, $hasOlder] = $conversation instanceof Conversation ? $this->page($conversation, 0, $after, $before) : [new Collection(), false];

        return [
            'messages' => $messages,
            'has_older' => $hasOlder,
            'reactions_version' => $conversation->reactions_version ?? 0,
            'reaction_changes' => $this->reactionChanges($conversation, 0, $reactionsAfter),
            'read_through_id' => $mine->read_through_id ?? 0,
            'partner_read_through_id' => $sharesReads ? $theirs->read_through_id : 0,
            'partner_read_at' => $sharesReads ? $theirs->read_at?->toJSON() : null,
            'partner_typing' => $access === PushAccess::Open && $conversation instanceof Conversation && Cache::has(UpdateConversationTyping::key($partner, $conversation)),
            'is_archived' => $mine?->archived_at !== null,
            'pinned_at' => $mine?->pinned_at?->toJSON(),
            'muted_until_at' => $mine?->muted_until_at?->isFuture() ? $mine->muted_until_at->toJSON() : null,
            'access' => $access,
        ];
    }

    /**
     * An org the user is in, from where their history starts. Each member's reads show only when both share pulls.
     * The messages the org pinned come whatever history the user was given, and polls get them again only once the
     * reactions version moved past the one they sent, as pinning and unpinning move it. They are null when unchanged,
     * and on older pages, where the thread keeps the ones it has.
     *
     * @return OrgThread
     */
    public function org(User $user, Conversation $org, ?int $after = null, ?int $before = null, ?int $reactionsAfter = null): array
    {
        $participants = $org->participants()->with('user')->withExists([
            'user as suggests_mentions' => fn (Builder $query): Builder => $query
                ->whereKeyNot($user->id)
                ->whereNotIn('id', $user->mutedIds())
                ->whereNotIn('id', $user->idsAcrossBlocks()),
            'user as is_followed' => fn (Builder $query): Builder => $query
                ->whereIn('id', $user->following()->select('users.id')),
        ])->oldest('id')->get();
        $mine = $participants->firstOrFail(fn (ConversationParticipant $participant): bool => $participant->user_id === $user->id);
        [$messages, $hasOlder] = $this->page($org, $mine->visible_from_id, $after, $before, withPeople: true);

        $typingUsers = $participants
            ->reject(fn (ConversationParticipant $participant): bool => $participant->user_id === $user->id)
            ->mapWithKeys(fn (ConversationParticipant $participant): array => [
                UpdateConversationTyping::key($participant->user, $org) => $participant->user->username,
            ]);
        $typingStatuses = Cache::many($typingUsers->keys()->all());

        return [
            'messages' => $messages,
            'has_older' => $hasOlder,
            'reactions_version' => $org->reactions_version,
            'reaction_changes' => $this->reactionChanges($org, $mine->visible_from_id, $reactionsAfter),
            'pinned' => $before === null && ($reactionsAfter === null || $org->reactions_version > $reactionsAfter) ? $this->pinnedMessages->get($org) : null,
            'read_through_id' => $mine->read_through_id,
            'members' => $participants->map(fn (ConversationParticipant $participant): array => [
                'user' => $participant->user,
                'role' => $participant->role,
                'read_through_id' => $user->pull_receipts && $participant->user->pull_receipts ? $participant->read_through_id : 0,
                'suggests_mentions' => (bool) $participant->getAttribute('suggests_mentions'),
                'is_followed' => (bool) $participant->getAttribute('is_followed'),
            ])->values()->toBase(),
            'typing' => $typingUsers
                ->filter(fn (string $username, string $key): bool => (bool) ($typingStatuses[$key] ?? false))
                ->values()
                ->all(),
            'is_archived' => $mine->archived_at !== null,
            'pinned_at' => $mine->pinned_at?->toJSON(),
            'muted_until_at' => $mine->muted_until_at?->isFuture() ? $mine->muted_until_at->toJSON() : null,
            'role' => $mine->role,
            'access' => PushAccess::Open,
        ];
    }

    /**
     * The current reactions of every message they changed on since the version a poll last saw, wherever the message
     * is in the thread. The version is read with the conversation, before this, so a change landing in between is
     * handed over again next time rather than skipped.
     *
     * @return Collection<int, ReactionChange>
     */
    private function reactionChanges(?Conversation $conversation, int $visibleFrom, ?int $since): Collection
    {
        if ($since === null || ! $conversation instanceof Conversation) {
            return new Collection();
        }

        return $conversation->messages()
            ->select(['id', 'conversation_id'])
            ->where('id', '>', $visibleFrom)
            ->where('reactions_version', '>', $since)
            ->with('reactions')
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message): array => ['id' => $message->id, 'reactions' => $message->reactions])
            ->toBase();
    }

    /**
     * The latest page, the page before a message for scrolling back, or everything after one for polling.
     *
     * @return array{Collection<int, Message>, bool}
     */
    private function page(Conversation $conversation, int $visibleFrom, ?int $after, ?int $before, bool $withPeople = false): array
    {
        $query = $conversation->messages()
            ->where('id', '>', $visibleFrom)
            ->with([...Message::SHOWN_WITH, ...$withPeople ? ['user', 'subject'] : []]);

        $messages = $after === null
            ? $query->where('id', '<', $before ?? PHP_INT_MAX)->orderByDesc('id')->limit(self::PAGE + 1)->get()->reverse()->values()
            : $query->where('id', '>', $after)->orderBy('id')->limit(self::PAGE * 2)->get();

        $hasOlder = $after === null && $messages->count() > self::PAGE;

        return [($hasOlder ? $messages->slice(1)->values() : $messages)->toBase(), $hasOlder];
    }
}
