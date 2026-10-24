<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

final readonly class PinnedMessagesQuery
{
    /**
     * The messages an org pinned, latest pinned first, for everyone in the org whatever history they were given. Each
     * comes with its author, images, voice note and reactions, but never the message it replies to, not even its id,
     * as that may be from before the viewer's history and isn't pinned itself.
     *
     * @return Collection<int, Message>
     */
    public function get(Conversation $org): Collection
    {
        return $org->messages()
            ->whereNotNull('pinned_at')
            ->with(['images', 'voice', 'reactions', 'user'])
            ->orderByDesc('pinned_at')
            ->orderByDesc('id')
            ->get()
            ->makeHidden('reply_to_id')
            ->toBase();
    }
}
