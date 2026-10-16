<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\LevelProgression;

enum UnixRank: string
{
    case User = 'user';
    case Daemon = 'daemon';
    case Operator = 'operator';
    case Maintainer = 'maintainer';
    case Wheel = 'wheel';
    case Root = 'root';

    public static function forXp(int $xp): self
    {
        return LevelProgression::rankForLevel(LevelProgression::forXp($xp));
    }

    public function minLevel(): int
    {
        return match ($this) {
            self::User => 1,
            self::Daemon => 10,
            self::Operator => 25,
            self::Maintainer => 45,
            self::Wheel => 70,
            self::Root => 100,
        };
    }

    public function level(): int
    {
        return match ($this) {
            self::User => 1,
            self::Daemon => 2,
            self::Operator => 3,
            self::Maintainer => 4,
            self::Wheel => 5,
            self::Root => 6,
        };
    }

    public function minXp(): int
    {
        return LevelProgression::xpForLevel($this->minLevel());
    }

    public function description(): string
    {
        return match ($this) {
            self::User => 'Unprivileged user (UID 1000)',
            self::Daemon => 'Background process running in user space',
            self::Operator => 'System operator with elevated permissions',
            self::Maintainer => 'Codebase curator managing repository state',
            self::Wheel => 'Member of the trusted wheel administrative group',
            self::Root => 'Superuser with full kernel privileges (UID 0)',
        };
    }

    public function auraColor(): string
    {
        return match ($this) {
            self::User => 'gray',
            self::Daemon => 'cyan',
            self::Operator => 'blue',
            self::Maintainer => 'magenta',
            self::Wheel => 'yellow',
            self::Root => 'red',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::User => self::Daemon,
            self::Daemon => self::Operator,
            self::Operator => self::Maintainer,
            self::Maintainer => self::Wheel,
            self::Wheel => self::Root,
            self::Root => null,
        };
    }
}
