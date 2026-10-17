export function completedAchievementIds(achievements: {
    badges: { id: string; unlocked: boolean }[];
    ranks: { level: number; achieved: boolean }[];
}): string[] {
    return [
        ...achievements.badges
            .filter((badge) => badge.unlocked)
            .map((badge) => `badge:${badge.id}`),
        ...achievements.ranks
            .filter((rank) => rank.achieved)
            .map((rank) => `rank:${rank.level}`),
    ];
}

export function newlyCompletedAchievementIds(
    previous: readonly string[],
    next: readonly string[],
): string[] {
    const seen = new Set(previous);

    return next.filter((id) => !seen.has(id));
}

export function newlyUnlockedBadgeNames(
    previous: readonly string[],
    badges: readonly { id: string; name: string; unlocked: boolean }[],
): string[] {
    const seen = new Set(previous);

    return badges
        .filter((badge) => badge.unlocked && !seen.has(`badge:${badge.id}`))
        .map((badge) => badge.name);
}
