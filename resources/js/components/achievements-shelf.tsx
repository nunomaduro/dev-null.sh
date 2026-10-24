import {
    Award,
    Bomb,
    BotOff,
    Box,
    ChevronRight,
    Cookie,
    FileCode,
    Flame,
    GitMerge,
    GitPullRequest,
    Key,
    Server,
    Shield,
    Sparkles,
    Star,
    Terminal,
    Zap,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { DragonBallIcon } from '@/components/dragon-ball-icon';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { WordmarkText } from '@/components/wordmark-text';
import { dropConfetti } from '@/lib/confetti';
import { formatCount, formatExactCount } from '@/lib/numbers';
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
    'git-pull-request': GitPullRequest,
    key: Key,
    cookie: Cookie,
    'bot-off': BotOff,
    bomb: Bomb,
    'dragon-ball': DragonBallIcon,
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
    const unlockedBadges = achievements.badges.filter((b) => b.unlocked);
    const unlockedCount = unlockedBadges.length;
    const [visibleCount, setVisibleCount] = useState(0);
    const [open, setOpen] = useState(false);
    const rowRef = useRef<HTMLButtonElement>(null);
    const moreRef = useRef<HTMLSpanElement>(null);
    const contentRef = useRef<HTMLDivElement>(null);
    const hoverTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const openedByHover = useRef(false);
    const nextRank = achievements.ranks.find(
        (r) => r.level === achievements.level + 1,
    );

    useLayoutEffect(() => {
        const row = rowRef.current;
        const more = moreRef.current;

        if (!row || !more) {
            return;
        }

        const measure = () => {
            const widths = Array.from(
                row.querySelectorAll<HTMLElement>('[data-earned-badge]'),
                (badge) => badge.getBoundingClientRect().width,
            );
            const gap = parseFloat(getComputedStyle(row).columnGap);
            const total = widths.reduce(
                (sum, width) => sum + width + gap,
                -gap,
            );
            const available = row.clientWidth;
            let used = 0;
            let count = 0;

            for (const width of widths) {
                used += width + gap;

                if (total > available && used + more.offsetWidth > available) {
                    break;
                }

                count++;
            }

            setVisibleCount(count);
        };

        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(row);
        observer.observe(more);
        row.querySelectorAll('[data-earned-badge]').forEach((badge) => {
            observer.observe(badge);
        });

        return () => observer.disconnect();
    }, [achievements.badges]);

    useEffect(() => () => clearTimeout(hoverTimer.current), []);

    const scheduleClose = () => {
        clearTimeout(hoverTimer.current);

        if (openedByHover.current) {
            hoverTimer.current = setTimeout(() => {
                if (
                    rowRef.current?.contains(document.activeElement) ||
                    contentRef.current?.contains(document.activeElement)
                ) {
                    return;
                }

                setOpen(false);
            }, 200);
        }
    };

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
                    <span title={`${formatExactCount(achievements.xp)} XP`}>
                        {formatCount(achievements.xp)} XP
                    </span>
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
                    <span
                        title={
                            achievements.next_level_xp
                                ? `${formatExactCount(achievements.xp)} / ${formatExactCount(achievements.next_level_xp)} XP`
                                : `${formatExactCount(achievements.xp)} XP`
                        }
                    >
                        {achievements.next_level_xp
                            ? `${formatCount(achievements.xp)} / ${formatCount(achievements.next_level_xp)} XP`
                            : `${formatCount(achievements.xp)} XP`}
                    </span>
                    <span
                        title={
                            nextRank
                                ? `${formatExactCount(nextRank.min_xp - achievements.xp)} XP to ${nextRank.rank}`
                                : undefined
                        }
                    >
                        {nextRank
                            ? `${formatCount(nextRank.min_xp - achievements.xp)} XP to ${nextRank.rank}`
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

            <Popover
                open={open}
                onOpenChange={(value) => {
                    clearTimeout(hoverTimer.current);

                    if (value) {
                        openedByHover.current = false;
                    }

                    setOpen(value);
                }}
            >
                <PopoverTrigger asChild>
                    <button
                        ref={rowRef}
                        type="button"
                        aria-label="Show all achievements"
                        className="relative mt-3 flex h-7 w-full items-center gap-2 overflow-hidden rounded-sm text-left focus-visible:ring-2 focus-visible:ring-ring"
                        onPointerEnter={(event) => {
                            clearTimeout(hoverTimer.current);

                            if (event.pointerType === 'mouse' && !open) {
                                hoverTimer.current = setTimeout(() => {
                                    openedByHover.current = true;
                                    setOpen(true);
                                }, 300);
                            }
                        }}
                        onPointerLeave={scheduleClose}
                    >
                        {unlockedBadges.map((badge, index) => {
                            const Icon = iconMap[badge.icon] ?? Terminal;

                            return (
                                <span
                                    key={badge.id}
                                    data-earned-badge
                                    aria-hidden={index >= visibleCount}
                                    className={cn(
                                        'inline-flex shrink-0 items-center gap-1.5 rounded-md border bg-card px-2.5 py-1 font-mono text-xs whitespace-nowrap text-foreground shadow-xs',
                                        index >= visibleCount &&
                                            'invisible absolute',
                                    )}
                                >
                                    <Icon className="size-3.5" />
                                    {badge.name}
                                </span>
                            );
                        })}
                        <span
                            ref={moreRef}
                            aria-hidden={visibleCount === unlockedCount}
                            className={cn(
                                'shrink-0 font-mono text-xs whitespace-nowrap text-muted-foreground tabular-nums',
                                visibleCount === unlockedCount &&
                                    'invisible absolute',
                            )}
                            style={{
                                width: `${String(unlockedCount).length + 6}ch`,
                            }}
                        >
                            +{unlockedCount - visibleCount} more
                        </span>
                        {unlockedCount === 0 && (
                            <span className="font-mono text-xs text-muted-foreground">
                                View achievements
                            </span>
                        )}
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    ref={contentRef}
                    align="start"
                    aria-label="All achievements"
                    className="max-h-(--radix-popover-content-available-height) w-96 max-w-[calc(100vw-2rem)] overflow-y-auto overscroll-contain"
                    onPointerEnter={() => clearTimeout(hoverTimer.current)}
                    onPointerLeave={scheduleClose}
                    onEscapeKeyDown={() => {
                        if (
                            contentRef.current?.contains(document.activeElement)
                        ) {
                            rowRef.current?.focus();
                        }
                    }}
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();

                        if (!openedByHover.current) {
                            contentRef.current?.focus();
                        }
                    }}
                    onCloseAutoFocus={(event) => {
                        if (openedByHover.current) {
                            event.preventDefault();
                        }
                    }}
                >
                    <p className="mb-3 text-sm font-medium">All achievements</p>
                    <div className="flex flex-wrap gap-2">
                        {achievements.badges.map((badge) => {
                            const Icon = iconMap[badge.icon] ?? Terminal;

                            return (
                                <Tooltip key={badge.id}>
                                    <TooltipTrigger asChild>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (badge.unlocked) {
                                                    dropConfetti();
                                                }

                                                clearTimeout(
                                                    hoverTimer.current,
                                                );
                                                setOpen(false);
                                                onInspect();
                                            }}
                                            className={cn(
                                                'inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1 font-mono text-xs transition-colors select-none',
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
                                            <WordmarkText>
                                                {badge.description}
                                            </WordmarkText>
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            );
                        })}
                    </div>
                </PopoverContent>
            </Popover>
        </section>
    );
}
