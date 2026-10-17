<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\PushAccess;
use App\Enums\RemoteStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;

final readonly class PushAccessQuery
{
    /**
     * A fetched request must be merged before replying, and the sender waits until it is, even after it is dropped,
     * so dropping stays private.
     */
    public static function of(User $partner, ?ConversationParticipant $mine, ?ConversationParticipant $theirs): PushAccess
    {
        return match (true) {
            ! $partner->remotes_enabled => PushAccess::Closed,
            $mine?->status === RemoteStatus::Fetched => PushAccess::Request,
            $theirs instanceof ConversationParticipant && $theirs->status !== RemoteStatus::Open => PushAccess::Waiting,
            default => PushAccess::Open,
        };
    }

    public function get(User $user, User $partner): PushAccess
    {
        $participants = ConversationParticipant::query()
            ->whereIn('conversation_id', Conversation::query()->select('id')->where('pair', Conversation::pairOf($user, $partner)))
            ->get()
            ->keyBy('user_id');

        return self::of($partner, $participants->get($user->id), $participants->get($partner->id));
    }
}
