<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\UpdateConversationTyping;
use App\Enums\PushAccess;
use App\Enums\RemoteStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final readonly class ConversationThreadQuery
{
    public const int PAGE = 50;

    /**
     * The latest page, the page before a message for scrolling back, or everything after one for polling. Reads
     * only show as pulled when both people share them, and only once the partner has merged the conversation.
     *
     * @return array{messages: Collection<int, Message>, has_older: bool, read_through_id: int, partner_read_through_id: int, partner_read_at: string|null, partner_typing: bool, is_archived: bool, access: PushAccess}
     */
    public function get(User $user, User $partner, ?int $after = null, ?int $before = null): array
    {
        $conversation = Conversation::query()->where('pair', Conversation::pairOf($user, $partner))->first();

        $participants = $conversation instanceof Conversation
            ? $conversation->participants()->get()->keyBy('user_id')
            : new Collection();
        $mine = $participants->get($user->id);
        $theirs = $participants->get($partner->id);
        $access = PushAccessQuery::of($partner, $mine, $theirs);
        $sharesReads = $user->pull_receipts && $partner->pull_receipts && $theirs?->status === RemoteStatus::Open;

        $messages = match (true) {
            ! $conversation instanceof Conversation => new Collection(),
            $after === null => $conversation->messages()
                ->where('id', '<', $before ?? PHP_INT_MAX)
                ->orderByDesc('id')
                ->limit(self::PAGE + 1)
                ->get()
                ->reverse()
                ->values(),
            default => $conversation->messages()
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::PAGE * 2)
                ->get(),
        };

        $hasOlder = $after === null && $messages->count() > self::PAGE;

        return [
            'messages' => ($hasOlder ? $messages->slice(1)->values() : $messages)->toBase(),
            'has_older' => $hasOlder,
            'read_through_id' => $mine->read_through_id ?? 0,
            'partner_read_through_id' => $sharesReads ? $theirs->read_through_id : 0,
            'partner_read_at' => $sharesReads ? $theirs->read_at?->toJSON() : null,
            'partner_typing' => $access === PushAccess::Open && Cache::has(UpdateConversationTyping::key($partner, $user)),
            'is_archived' => $mine?->archived_at !== null,
            'access' => $access,
        ];
    }
}
