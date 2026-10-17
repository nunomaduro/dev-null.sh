<?php

declare(strict_types=1);

namespace App\Enums;

enum InboxTab: string
{
    case Open = 'open';
    case Fetched = 'fetched';
    case Stashed = 'stashed';
}
