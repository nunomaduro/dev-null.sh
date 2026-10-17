<?php

declare(strict_types=1);

namespace App\Support;

/** One-time, claimable milestone gifts; no purchase or cash-out. */
final class LevelRewards
{
    /** @var array<int, array{bytes: int, skin: ?string}> */
    public const array MILESTONES = [
        5 => ['bytes' => 25, 'skin' => null],
        10 => ['bytes' => 75, 'skin' => null],
        25 => ['bytes' => 250, 'skin' => 'matrix'],
        45 => ['bytes' => 750, 'skin' => null],
        70 => ['bytes' => 1600, 'skin' => 'kernel-red'],
        100 => ['bytes' => 5000, 'skin' => null],
    ];
}
