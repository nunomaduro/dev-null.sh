<?php

declare(strict_types=1);

namespace App\Enums;

enum CommitGift: string
{
    case Coffee = 'coffee';
    case Beer = 'beer';
    case Overclock = 'overclock';
    case Rocket = 'rocket';
    case Root = 'root';
    case Signal = 'signal';
    case Phoenix = 'phoenix';
    case Eclipse = 'eclipse';

    public function label(): string
    {
        return match ($this) {
            self::Coffee => 'Hot Coffee',
            self::Beer => 'Cold Brew',
            self::Overclock => 'Overclock',
            self::Rocket => 'Ship to Prod',
            self::Root => 'Sudo Grant',
            self::Signal => 'Signal Beacon',
            self::Phoenix => 'Null Phoenix',
            self::Eclipse => 'Kernel Eclipse',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Coffee => 'coffee',
            self::Beer => 'beer',
            self::Overclock => 'zap',
            self::Rocket => 'rocket',
            self::Root => 'shield-alert',
            self::Signal => 'radio-tower',
            self::Phoenix => 'flame',
            self::Eclipse => 'orbit',
        };
    }

    public function cost(): int
    {
        return match ($this) {
            self::Coffee => 10,
            self::Beer => 25,
            self::Overclock => 50,
            self::Rocket => 100,
            self::Root => 250,
            self::Signal => 75,
            self::Phoenix => 175,
            self::Eclipse => 500,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Coffee => 'Fuel late-night commits with a piping hot cup of coffee.',
            self::Beer => 'Celebrate a merged pull request with a refreshing cold brew.',
            self::Overclock => 'Overclock the processor and boost developer velocity.',
            self::Rocket => 'Deploy directly to production with zero regressions.',
            self::Root => 'Bestow superuser privileges and supreme developer respect.',
            self::Signal => 'A tiny transmission to say your code was heard.',
            self::Phoenix => 'A terminal phoenix for an elegant comeback.',
            self::Eclipse => 'A rare orbiting kernel, reserved for outstanding contributions.',
        };
    }
}
