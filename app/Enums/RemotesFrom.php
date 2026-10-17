<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who can start a conversation straight away. Anyone else lands in the recipient's fetched requests.
 */
enum RemotesFrom: string
{
    case Following = 'following';
    case Everyone = 'everyone';
}
