<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a conversation sits for one of its two people. A request arrives fetched, like `git fetch`: there, but not
 * merged into the inbox until accepted. Dropping it hides it for good, without telling the sender.
 */
enum RemoteStatus: string
{
    case Open = 'open';
    case Fetched = 'fetched';
    case Dropped = 'dropped';
}
