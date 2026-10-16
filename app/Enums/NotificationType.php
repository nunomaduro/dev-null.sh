<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationType: string
{
    case Messaged = 'messaged';
    case MessageRequested = 'message_requested';
    case Followed = 'followed';
    case Replied = 'replied';
    case Mentioned = 'mentioned';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Quoted = 'quoted';
    case Starred = 'starred';
}
