<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UnixRank;

/**
 * Cumulative XP is deliberately superlinear; Level 100 is a long-term goal.
 * The same thresholds are used by profiles, rank badges and reward claims.
 */
final class LevelProgression
{
    public const int MAX_LEVEL = 100;

    public static function xpForLevel(int $level): int
    {
        $steps = max(0, min(self::MAX_LEVEL, $level) - 1);

        return 40 * $steps * $steps + intdiv($steps * $steps * $steps, 3);
    }

    public static function forXp(int $xp): int
    {
        $low = 1;
        $high = self::MAX_LEVEL;

        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);

            if (self::xpForLevel($mid) <= $xp) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    /**
     * @return array{int, ?int, int}
     */
    public static function progress(int $xp): array
    {
        $level = self::forXp($xp);
        $nextLevelXp = $level < self::MAX_LEVEL ? self::xpForLevel($level + 1) : null;
        $progress = $nextLevelXp !== null
            ? min(100, max(0, (int) round((($xp - self::xpForLevel($level)) / ($nextLevelXp - self::xpForLevel($level))) * 100)))
            : 100;

        return [$level, $nextLevelXp, $progress];
    }

    public static function rankForLevel(int $level): UnixRank
    {
        return match (true) {
            $level >= 100 => UnixRank::Root,
            $level >= 70 => UnixRank::Wheel,
            $level >= 45 => UnixRank::Maintainer,
            $level >= 25 => UnixRank::Operator,
            $level >= 10 => UnixRank::Daemon,
            default => UnixRank::User,
        };
    }
}
