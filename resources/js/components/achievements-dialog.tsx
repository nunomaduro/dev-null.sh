import {
    Box,
    FileCode,
    Flame,
    GitMerge,
    Key,
    Server,
    Shield,
    Sparkles,
    Star,
    Terminal,
    Zap,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { Achievements, Profile, User } from '@/types';

const iconMap: Record<string, React.ComponentType<{ className?: string }>> = {
    terminal: Terminal,
    'file-code': FileCode,
    flame: Flame,
    zap: Zap,
    server: Server,
    box: Box,
    shield: Shield,
    star: Star,
    sparkles: Sparkles,
    'git-merge': GitMerge,
    key: Key,
};

export function AchievementsDialog({
    open,
    onOpenChange,
    achievements,
    user,
    lane,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    achievements: Achievements;
    user: User | Profile;
    lane: string;
}) {
    const [tab, setTab] = useState<'badges' | 'ranks'>('badges');

    const unlockedCount = achievements.badges.filter((b) => b.unlocked).length;
    const nextRank = achievements.ranks.find(
        (r) => r.level === achievements.level + 1,
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[85vh] overflow-y-auto sm:max-w-xl"
                style={{ '--lane': lane } as CSSProperties}
            >
                <DialogHeader className="text-left">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <DialogTitle className="text-base font-semibold">
                            Developer Achievements
                        </DialogTitle>
                        <span
                            className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-mono text-xs font-medium text-foreground"
                            style={{
                                borderColor: `${lane}40`,
                                backgroundColor: `${lane}15`,
                            }}
                        >
                            Lv. {achievements.level} · {achievements.rank}
                        </span>
                    </div>
                    <DialogDescription className="font-mono text-xs text-muted-foreground">
                        @{user.username} · {achievements.xp} XP ·{' '}
                        {unlockedCount}/{achievements.badges.length} badges
                        unlocked
                    </DialogDescription>
                </DialogHeader>

                {/* Rank XP Progress */}
                <div className="rounded-lg border bg-muted/30 p-3 font-mono text-xs">
                    <div className="flex items-center justify-between text-muted-foreground">
                        <span>
                            {achievements.next_level_xp
                                ? `${achievements.xp} / ${achievements.next_level_xp} XP`
                                : `${achievements.xp} XP`}
                        </span>
                        <span>
                            {nextRank
                                ? `${nextRank.min_xp - achievements.xp} XP to ${nextRank.rank}`
                                : 'Maximum rank (UID 0)'}
                        </span>
                    </div>
                    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full transition-all duration-500"
                            style={{
                                width: `${achievements.level_progress}%`,
                                backgroundColor: lane,
                            }}
                        />
                    </div>
                </div>

                {/* View Switcher */}
                <div className="flex border-b font-mono text-xs">
                    <button
                        type="button"
                        onClick={() => setTab('badges')}
                        className={cn(
                            'border-b-2 px-3 py-1.5 font-medium transition',
                            tab === 'badges'
                                ? 'border-(--lane) text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        Badges ({achievements.badges.length})
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('ranks')}
                        className={cn(
                            'border-b-2 px-3 py-1.5 font-medium transition',
                            tab === 'ranks'
                                ? 'border-(--lane) text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        Unix Ranks ({achievements.ranks.length})
                    </button>
                </div>

                {/* Badges Grid */}
                {tab === 'badges' && (
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {achievements.badges.map((badge) => {
                            const Icon = iconMap[badge.icon] ?? Terminal;

                            return (
                                <div
                                    key={badge.id}
                                    className={cn(
                                        'flex flex-col justify-between rounded-lg border p-3 font-mono text-xs transition-colors',
                                        badge.unlocked
                                            ? 'border-border bg-card shadow-xs'
                                            : 'border-dashed border-border/80 bg-muted/20 text-muted-foreground',
                                    )}
                                >
                                    <div>
                                        <div className="flex items-center justify-between gap-1.5">
                                            <div className="flex items-center gap-2">
                                                <div
                                                    className={cn(
                                                        'flex size-6 shrink-0 items-center justify-center rounded border',
                                                        badge.unlocked
                                                            ? 'border-border bg-accent text-foreground'
                                                            : 'border-border/60 bg-muted text-muted-foreground/60',
                                                    )}
                                                >
                                                    <Icon className="size-3.5" />
                                                </div>
                                                <span className="font-semibold text-foreground">
                                                    {badge.name}
                                                </span>
                                            </div>
                                            <span
                                                className={cn(
                                                    'rounded px-1.5 py-0.5 text-[10px] font-medium',
                                                    badge.unlocked
                                                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {badge.unlocked
                                                    ? '✓'
                                                    : `${badge.progress}/${badge.target}`}
                                            </span>
                                        </div>
                                        <p className="mt-1.5 font-sans text-xs leading-normal text-muted-foreground">
                                            {badge.description}
                                        </p>
                                    </div>

                                    {!badge.unlocked && badge.target > 1 && (
                                        <div className="mt-2.5 h-1 w-full overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full rounded-full bg-muted-foreground/40 transition-all duration-300"
                                                style={{
                                                    width: `${Math.min(100, Math.round((badge.progress / badge.target) * 100))}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Unix Ranks Roadmap */}
                {tab === 'ranks' && (
                    <div className="space-y-2">
                        {achievements.ranks.map((rank) => {
                            const isCurrent = rank.level === achievements.level;

                            return (
                                <div
                                    key={rank.rank}
                                    className={cn(
                                        'flex items-center justify-between gap-3 rounded-lg border p-3 font-mono text-xs transition-colors',
                                        isCurrent
                                            ? 'border-(--lane) bg-card ring-1 ring-(--lane)'
                                            : rank.achieved
                                              ? 'border-border bg-card'
                                              : 'border-dashed border-border/60 bg-muted/20',
                                    )}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <span
                                            className={cn(
                                                'flex size-6 shrink-0 items-center justify-center rounded text-xs font-bold',
                                                isCurrent
                                                    ? 'bg-accent text-foreground ring-1 ring-(--lane)'
                                                    : rank.achieved
                                                      ? 'bg-accent text-foreground'
                                                      : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {rank.level}
                                        </span>
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-foreground">
                                                    {rank.rank}
                                                </span>
                                                {isCurrent && (
                                                    <span className="py-0.2 rounded bg-accent px-1.5 text-[10px] font-medium text-foreground">
                                                        CURRENT
                                                    </span>
                                                )}
                                            </div>
                                            <p className="font-sans text-[11px] text-muted-foreground">
                                                {rank.description}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="shrink-0 text-right">
                                        <span className="font-medium text-foreground">
                                            {rank.min_xp} XP
                                        </span>
                                        <div className="text-[10px] text-muted-foreground">
                                            {rank.achieved ? (
                                                <span className="text-emerald-700 dark:text-emerald-400">
                                                    ✓ Achieved
                                                </span>
                                            ) : (
                                                <span>
                                                    Requires {rank.min_xp} XP
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* Dry Unix XP Formula Footnote */}
                <p className="border-t pt-2 text-center font-mono text-[11px] text-muted-foreground">
                    XP: 10/commit · 15/day streak · 5/star received · 5/reply ·
                    2/star given
                </p>
            </DialogContent>
        </Dialog>
    );
}
