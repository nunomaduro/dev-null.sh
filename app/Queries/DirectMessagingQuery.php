<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\CursorPaginator;

final readonly class DirectMessagingQuery
{
    public function between(User $sender, User $recipient): ?Conversation
    {
        $ids = [$sender->id, $recipient->id];
        sort($ids);

        return Conversation::query()->where('first_user_id', $ids[0])->where('second_user_id', $ids[1])->first();
    }

    public function canSend(User $sender, User $recipient, ?Conversation $conversation = null): bool
    {
        if ($sender->is($recipient) || $sender->dm_policy === 'off' || $recipient->dm_policy === 'off' || $sender->hasBlockWith($recipient)) {
            return false;
        }

        $conversation ??= $this->between($sender, $recipient);

        return $conversation instanceof Conversation
            ? $conversation->status === 'accepted'
            : $recipient->isFollowing($sender) || $recipient->dm_policy === 'everyone';
    }

    /** @return Builder<ConversationMember> */
    public function memberships(User $user): Builder
    {
        return ConversationMember::query()->where('user_id', $user->id)
            ->whereHas('conversation', fn (Builder $query): Builder => $query
                ->whereNotIn('first_user_id', $user->idsAcrossBlocks())
                ->whereNotIn('second_user_id', $user->idsAcrossBlocks()));
    }

    /** @return CursorPaginator<int, Conversation> */
    public function inbox(User $user, string $folder, string $search): CursorPaginator
    {
        return Conversation::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->where('user_id', $user->id)->where('archived', $folder === 'archived'))
            ->whereNotIn('first_user_id', $user->idsAcrossBlocks())->whereNotIn('second_user_id', $user->idsAcrossBlocks())
            ->when($folder === 'requests', fn (Builder $query): Builder => $query->where('status', 'requested')->where('initiator_id', '!=', $user->id))
            ->when($folder === 'inbox', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('status', 'accepted')->orWhere('initiator_id', $user->id)))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q
                ->whereHas('firstUser', fn (Builder $other): Builder => $other->whereKeyNot($user->id)->where('username', 'ilike', '%'.$search.'%'))
                ->orWhereHas('secondUser', fn (Builder $other): Builder => $other->whereKeyNot($user->id)->where('username', 'ilike', '%'.$search.'%'))))
            ->with(['firstUser', 'secondUser', 'members', 'latestMessage'])
            ->withExists(['messages as has_unread_messages' => fn (Builder $messages): Builder => $messages
                ->where('sender_id', '!=', $user->id)
                ->where('id', '>', fn (QueryBuilder $member): QueryBuilder => $member->select('last_read_message_id')->from('conversation_members')
                    ->whereColumn('conversation_id', 'conversations.id')->where('user_id', $user->id))])
            ->latest('last_message_at')->orderByDesc('id')->cursorPaginate(20)->withQueryString();
    }

    public function unreadCount(User $user): int
    {
        if ($user->dm_policy === 'off') {
            return 0;
        }

        return $this->memberships($user)->where('muted', false)->where('archived', false)
            ->whereHas('conversation', fn (Builder $query): Builder => $query
                ->where('status', '!=', 'declined')
                ->whereExists(fn (QueryBuilder $messages): QueryBuilder => $messages->from('direct_messages')
                    ->whereColumn('direct_messages.conversation_id', 'conversations.id')
                    ->whereColumn('direct_messages.id', '>', 'conversation_members.last_read_message_id')
                    ->where('sender_id', '!=', $user->id)
                    ->whereNotIn('sender_id', $user->mutedIds())))
            ->count();
    }

    /** @return array<string, mixed> */
    public function summary(Conversation $conversation, User $user): array
    {
        $conversation->loadMissing(['firstUser', 'secondUser', 'members', 'latestMessage']);
        $other = $conversation->first_user_id === $user->id ? $conversation->secondUser : $conversation->firstUser;
        $member = $conversation->members->firstWhere('user_id', $user->id);
        assert($member instanceof ConversationMember);
        $last = $conversation->latestMessage;
        $otherMember = $conversation->members->firstWhere('user_id', $other->id);
        assert($otherMember instanceof ConversationMember);

        return [
            'id' => $conversation->id,
            'other' => $other,
            'status' => $conversation->status === 'declined' && $conversation->initiator_id === $user->id ? 'requested' : $conversation->status,
            'incoming_request' => $conversation->status !== 'accepted' && $conversation->initiator_id !== $user->id,
            'can_accept' => $user->dm_policy !== 'off' && $other->dm_policy !== 'off',
            'can_send' => $conversation->status === 'accepted' && $user->dm_policy !== 'off' && $other->dm_policy !== 'off',
            'muted' => $member->muted,
            'archived' => $member->archived,
            'last_message' => $last,
            'unread' => $conversation->getAttributes()['has_unread_messages'] ?? $conversation->messages()->where('sender_id', '!=', $user->id)->where('id', '>', $member->last_read_message_id)->exists(),
            'read_through' => $conversation->status === 'accepted' && $user->dm_read_receipts && $other->dm_read_receipts ? $otherMember->last_read_message_id : null,
        ];
    }
}
