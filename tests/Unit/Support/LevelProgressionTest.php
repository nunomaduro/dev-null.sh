<?php

declare(strict_types=1);

use App\Enums\UnixRank;
use App\Support\LevelProgression;

it('has one hundred strictly increasing, increasingly expensive levels', function (): void {
    expect(LevelProgression::xpForLevel(1))->toBe(0)
        ->and(LevelProgression::xpForLevel(2))->toBe(40)
        ->and(LevelProgression::xpForLevel(100))->toBeGreaterThan(700000)
        ->and(LevelProgression::forXp(PHP_INT_MAX))->toBe(100)
        ->and(LevelProgression::forXp(-100))->toBe(1);

    for ($level = 1; $level < 100; $level++) {
        $xp = LevelProgression::xpForLevel($level);
        $next = LevelProgression::xpForLevel($level + 1);
        expect($next)->toBeGreaterThan($xp)
            ->and(LevelProgression::forXp($xp))->toBe($level)
            ->and(LevelProgression::forXp($next - 1))->toBe($level);
    }
});

it('makes unix ranks milestones', function (): void {
    expect(LevelProgression::rankForLevel(1))->toBe(UnixRank::User)
        ->and(LevelProgression::rankForLevel(10))->toBe(UnixRank::Daemon)
        ->and(LevelProgression::rankForLevel(25))->toBe(UnixRank::Operator)
        ->and(LevelProgression::rankForLevel(45))->toBe(UnixRank::Maintainer)
        ->and(LevelProgression::rankForLevel(70))->toBe(UnixRank::Wheel)
        ->and(LevelProgression::rankForLevel(100))->toBe(UnixRank::Root);
});

it('computes progress correctly at the start, between levels and at level 100', function (): void {
    expect(LevelProgression::progress(0))->toBe([1, 40, 0])
        ->and(LevelProgression::progress(20))->toBe([1, 40, 50])
        ->and(LevelProgression::progress(LevelProgression::xpForLevel(100)))
        ->toBe([100, null, 100]);
});

it('exercises rank XP lookup, legacy rank ordinals, next-rank links and aura colors', function (): void {
    $ranks = UnixRank::cases();
    $colors = ['gray', 'cyan', 'blue', 'magenta', 'yellow', 'red'];

    foreach ($ranks as $index => $rank) {
        expect(UnixRank::forXp($rank->minXp()))->toBe($rank)
            ->and($rank->level())->toBe($index + 1)
            ->and($rank->next())->toBe($ranks[$index + 1] ?? null)
            ->and($rank->auraColor())->toBe($colors[$index]);
    }
});
