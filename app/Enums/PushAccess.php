<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the composer can do in a conversation, as seen by one of its two people.
 */
enum PushAccess: string
{
    /**
     * Push freely, or start a new conversation.
     */
    case Open = 'open';

    /**
     * The partner's request is fetched here: merge it before replying.
     */
    case Request = 'request';

    /**
     * The user's own request waits for the partner to merge it.
     */
    case Waiting = 'waiting';

    /**
     * The partner has remotes turned off.
     */
    case Closed = 'closed';
}
