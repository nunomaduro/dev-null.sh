import {
    Award,
    Box,
    ChevronRight,
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
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { Achievements } from '@/types';

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

export function AchievementsShelf({
    achievements,
    lane,
    onInspect,
}: {
    achievements: Achievements;
    lane: string;
    onInspect: () => void;
}) {
    const unlockedCount = achievements.badges.filter((b) => b.unlocked).length;
    const nextRank = achievements.ranks.find(
        (r) => r.level === achievements.level + 1,
    );

    return (
        <section
            aria-label="Developer achievements"
            className="border-b px-4 py-3 sm:px-6"
        >
            <div className="flex flex-wrap items-center justify-between gap-2 font-mono text-[13px] text-muted-foreground">
                <button
                    type="button"
                    onClick={onInspect}
                    className="group flex items-center gap-2 text-left transition hover:text-foreground"
                >
                    <Award className="size-4 text-foreground" />
                    <span className="font-medium text-foreground group-hover:underline">
                        Lv. {achievements.level} · {achievements.rank}
                    </span>
                    <span aria-hidden>·</span>
                    <span>{achievements.xp} XP</span>
                </button>
                <button
                    type="button"
                    onClick={onInspect}
                    className="flex items-center gap-1 text-xs text-muted-foreground transition hover:text-foreground hover:underline"
                >
                    <span>
                        {unlockedCount} / {achievements.badges.length} unlocked
                    </span>
                    <ChevronRight className="size-3.5" />
                </button>
            </div>

            {/* Integrated XP Progress Bar */}
            <div className="mt-2.5">
                <div className="flex items-center justify-between font-mono text-[11px] text-muted-foreground">
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
                <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full transition-all duration-500"
                        style={{
                            width: `${achievements.level_progress}%`,
                            backgroundColor: lane,
                        }}
                    />
                </div>
            </div>

            <TooltipProvider delayDuration={150}>
                <div className="mt-3 flex flex-wrap gap-2">
                    {achievements.badges.map((badge) => {
                        const Icon = iconMap[badge.icon] ?? Terminal;

                        return (
                            <Tooltip key={badge.id}>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        onClick={onInspect}
                                        className={cn(
                                            'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 font-mono text-xs transition-colors select-none',
                                            badge.unlocked
                                                ? 'border-border bg-card text-foreground shadow-xs hover:border-(--lane)'
                                                : 'border-dashed border-muted-foreground/30 bg-muted/20 text-muted-foreground hover:border-muted-foreground/60',
                                        )}
                                        style={
                                            badge.unlocked
                                                ? ({
                                                      '--lane': lane,
                                                  } as CSSProperties)
                                                : undefined
                                        }
                                    >
                                        <Icon
                                            className={cn(
                                                'size-3.5',
                                                badge.unlocked
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground/50',
                                            )}
                                        />
                                        <span>{badge.name}</span>
                                        {!badge.unlocked &&
                                            badge.target > 1 && (
                                                <span className="text-[10px] text-muted-foreground">
                                                    ({badge.progress}/
                                                    {badge.target})
                                                </span>
                                            )}
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent
                                    side="top"
                                    className="max-w-xs font-mono text-xs"
                                >
                                    <p className="font-semibold text-foreground">
                                        {badge.name}{' '}
                                        {badge.unlocked
                                            ? '✓'
                                            : `(${badge.progress}/${badge.target})`}
                                    </p>
                                    <p className="mt-0.5 font-sans text-muted-foreground">
                                        {badge.description}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        );
                    })}
                </div>
            </TooltipProvider>
        </section>
    );
}
