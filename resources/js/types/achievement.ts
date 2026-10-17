export type AchievementBadge = {
    id: string;
    name: string;
    description: string;
    icon: string;
    unlocked: boolean;
    progress: number;
    target: number;
};

export type UnixRank = {
    rank: string;
    level: number;
    min_xp: number;
    description: string;
    achieved: boolean;
};

export type XpMetric = {
    count: number;
    multiplier: number;
    xp: number;
};

export type XpBreakdown = {
    commits: XpMetric;
    streak: XpMetric;
    stars_received: XpMetric;
    replies: XpMetric;
    stars_given: XpMetric;
    kudos_given: XpMetric;
    kudos_received: XpMetric;
    skins_unlocked: XpMetric;
};

export type Achievements = {
    level: number;
    rank: string;
    xp: number;
    next_level_xp: number | null;
    level_progress: number;
    xp_breakdown: XpBreakdown;
    level_rewards: {
        level: number;
        bytes: number;
        skin: string | null;
        claimed: boolean;
        eligible: boolean;
    }[];
    ranks: UnixRank[];
    badges: AchievementBadge[];
};
