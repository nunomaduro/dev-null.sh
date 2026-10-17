<?php

declare(strict_types=1);

namespace App\Enums;

enum TuiTab: string
{
    case Everyone = 'everyone';
    case Following = 'following';
    case Thread = 'thread';
    case Compose = 'compose';
    case Profile = 'profile';
    case Help = 'help';

    public function label(): string
    {
        return match ($this) {
            self::Everyone => '[1] Everyone',
            self::Following => '[2] Following',
            self::Thread => '[3] Thread',
            self::Compose => '[4] New Post',
            self::Profile => '[5] Profile & Ranks',
            self::Help => '[?] Help',
        };
    }
}
