import { Transition } from '@headlessui/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { Copy, Pin, PinOff, Search, SmilePlus } from 'lucide-react';
import type { KeyboardEvent, PointerEvent, ReactNode, RefObject } from 'react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { emojiName, searchEmojiByUse } from '@/lib/emoji';
import { formatCount, formatExactCount } from '@/lib/numbers';
import type { ReactionUsage, Tap } from '@/lib/reactions';
import {
    frequentReactions,
    isDoubleTap,
    quickReactions,
    summarizeReactions,
} from '@/lib/reactions';
import { cn } from '@/lib/utils';
import type { MessageReaction } from '@/types';

type EmojiGroup = { name: string; emoji: string[] };

const columns = 8;
const longPressDelay = 420;

let loadedGroups: EmojiGroup[] | null = null;
let loadingGroups: Promise<EmojiGroup[]> | null = null;

/**
 * The full emoji set, grouped as on a keyboard, loads on its own the first time someone is about to react.
 */
export function preloadEmojiGroups(): Promise<EmojiGroup[]> {
    loadingGroups ??= import('@/lib/emoji-groups.json').then((module) => {
        loadedGroups = module.default as EmojiGroup[];

        return loadedGroups;
    });

    return loadingGroups;
}

function useEmojiGroups(): EmojiGroup[] | null {
    const [groups, setGroups] = useState(loadedGroups);

    useEffect(() => {
        if (groups !== null) {
            return;
        }

        let isCurrent = true;

        void preloadEmojiGroups().then((loaded) => {
            if (isCurrent) {
                setGroups(loaded);
            }
        });

        return () => {
            isCurrent = false;
        };
    }, [groups]);

    return groups;
}

/**
 * Shows the skeleton only once loading takes long enough to notice.
 */
function useIsSlow(isLoading: boolean): boolean {
    const [isSlow, setIsSlow] = useState(false);

    useEffect(() => {
        if (!isLoading) {
            return;
        }

        const timer = window.setTimeout(() => setIsSlow(true), 300);

        return () => {
            window.clearTimeout(timer);
            setIsSlow(false);
        };
    }, [isLoading]);

    return isSlow;
}

function shortcodeOf(emoji: string): string {
    const name = emojiName(emoji);

    return name === undefined ? emoji : `:${name}:`;
}

/**
 * One chip per emoji under the message, pressed when the user left it. Pressing one adds or takes back theirs.
 */
export function ReactionChips({
    reactions,
    userId,
    isMine,
    nameOf,
    onToggle,
}: {
    reactions: MessageReaction[] | undefined;
    userId: string;
    isMine: boolean;
    nameOf: (userId: string) => string;
    onToggle: ((emoji: string) => void) | null;
}) {
    const hasReactions = (reactions?.length ?? 0) > 0;
    const [displayedReactions, setDisplayedReactions] = useState(reactions);

    // Keep the last chips in the fold until their row and its gap have collapsed.
    if (hasReactions && reactions !== displayedReactions) {
        setDisplayedReactions(reactions);
    }

    const summaries = summarizeReactions(
        hasReactions ? reactions : displayedReactions,
        userId,
    );

    return (
        <Transition show={hasReactions}>
            <div
                inert={!hasReactions}
                aria-hidden={!hasReactions}
                className="-mt-0.5 grid max-w-full grid-rows-[1fr] data-leave:data-closed:-mt-1 data-leave:data-closed:grid-rows-[0fr] data-leave:data-closed:opacity-0 motion-safe:data-leave:transition-[grid-template-rows,margin-top,opacity] motion-safe:data-leave:duration-300 motion-safe:data-leave:ease-out"
            >
                <div
                    className={cn(
                        'min-h-0',
                        !hasReactions && 'overflow-hidden',
                    )}
                >
                    <ul
                        aria-label="Reactions"
                        className={cn(
                            'flex max-w-full flex-wrap gap-1 pb-0.5',
                            {
                                'justify-end': isMine,
                            },
                        )}
                        data-test="message-reactions"
                    >
                        {summaries.map((summary) => (
                            <ReactionChip
                                key={summary.emoji}
                                summary={summary}
                                nameOf={nameOf}
                                onToggle={onToggle}
                                isLeaving={!hasReactions}
                            />
                        ))}
                    </ul>
                </div>
            </div>
        </Transition>
    );
}

function ReactionChip({
    summary,
    nameOf,
    onToggle,
    isLeaving,
}: {
    summary: ReturnType<typeof summarizeReactions>[number];
    nameOf: (userId: string) => string;
    onToggle: ((emoji: string) => void) | null;
    isLeaving: boolean;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const anchor = useRef<HTMLButtonElement>(null);
    const names = summary.userIds.map(nameOf).join(', ');
    const touch = useTouchReactions({
        onLongPress: () => setIsOpen(true),
    });

    return (
        <li>
            <Popover open={isOpen && !isLeaving} onOpenChange={setIsOpen}>
                <Tooltip open={isOpen ? false : undefined}>
                    <TooltipTrigger asChild>
                        <PopoverAnchor asChild>
                            <button
                                ref={anchor}
                                type="button"
                                disabled={isLeaving}
                                aria-pressed={summary.isMine}
                                aria-label={`${shortcodeOf(summary.emoji)} ${formatExactCount(summary.count)}, from ${names}`}
                                aria-description="Press and hold, or press Shift+F10, to see who reacted."
                                aria-haspopup="dialog"
                                aria-expanded={isOpen && !isLeaving}
                                {...touch}
                                onPointerDown={(event) => {
                                    event.stopPropagation();
                                    touch.onPointerDown?.(event);
                                }}
                                onKeyDown={(event) => {
                                    if (
                                        event.key === 'ContextMenu' ||
                                        (event.shiftKey && event.key === 'F10')
                                    ) {
                                        event.preventDefault();
                                        setIsOpen(true);
                                    }
                                }}
                                onClick={() => onToggle?.(summary.emoji)}
                                className={cn(
                                    'inline-flex h-6 items-center gap-1 rounded-md border bg-card pr-2 pl-1.5 text-[13px] leading-none transition-colors select-none [-webkit-touch-callout:none] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none enabled:hover:border-foreground/25',
                                    {
                                        'border-primary/45 bg-primary-soft enabled:hover:border-primary':
                                            summary.isMine,
                                    },
                                )}
                                data-test="reaction-chip"
                            >
                                <span aria-hidden>{summary.emoji}</span>
                                <span
                                    aria-hidden
                                    className={cn(
                                        'min-w-[1ch] font-mono text-[11px] font-medium text-muted-foreground tabular-nums',
                                        { 'text-primary': summary.isMine },
                                    )}
                                >
                                    {formatCount(summary.count)}
                                </span>
                            </button>
                        </PopoverAnchor>
                    </TooltipTrigger>
                    <TooltipContent>
                        {names} reacted with {shortcodeOf(summary.emoji)}
                    </TooltipContent>
                </Tooltip>
                <PopoverContent
                    side="top"
                    className="max-h-[min(18rem,var(--radix-popover-content-available-height))] max-w-[calc(100vw-2rem)] overflow-y-auto overscroll-contain p-3"
                    aria-label={`People who reacted with ${shortcodeOf(summary.emoji)}`}
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    onCloseAutoFocus={(event) => event.preventDefault()}
                    onFocusOutside={(event) => {
                        // Lifting the held finger focuses its chip before the synthetic click is suppressed.
                        if (anchor.current?.contains(event.target as Node)) {
                            event.preventDefault();
                        }
                    }}
                    data-test="reaction-authors"
                >
                    <p className="mb-2 flex items-center gap-2 text-sm font-medium">
                        <span aria-hidden>{summary.emoji}</span>
                        {formatExactCount(summary.count)}{' '}
                        {summary.count === 1 ? 'reaction' : 'reactions'}
                    </p>
                    <ul className="space-y-1 text-sm wrap-anywhere">
                        {summary.userIds.map((id) => (
                            <li key={id}>{nameOf(id)}</li>
                        ))}
                    </ul>
                </PopoverContent>
            </Popover>
        </li>
    );
}

/**
 * The quick reactions and the picker, in a row that unfolds under the message when its react button is pressed,
 * nudging what follows down, and folds back once an emoji is picked, Escape is pressed, or anything else is clicked.
 * It stays in the tree so the fold animates both ways, and is inert while folded. Beside its trigger, it floats over
 * the row before the trigger's positioned parent instead, so nothing moves.
 */
export function ReactionTray({
    id,
    isOpen,
    onOpenChange,
    trigger,
    usage,
    reacted,
    hasReactions,
    isMine,
    onReact,
    placement = 'below',
}: {
    id: string;
    isOpen: boolean;
    onOpenChange: (isOpen: boolean) => void;
    trigger: RefObject<HTMLButtonElement | null>;
    usage: ReactionUsage[];
    reacted: Set<string>;
    hasReactions: boolean;
    isMine: boolean;
    onReact: (emoji: string) => void;
    placement?: 'below' | 'beside';
}) {
    const tray = useRef<HTMLDivElement>(null);
    const [isPickerOpen, setIsPickerOpen] = useState(false);
    const isPicking = isOpen && isPickerOpen;
    const pickedEmoji = useRef<string | null>(null);
    const pendingReaction = useRef<string | null>(null);
    const [isReservingReaction, setIsReservingReaction] = useState(false);
    const reservesFirstReaction = isReservingReaction && !hasReactions;

    const close = () => {
        onOpenChange(false);
        trigger.current?.focus({ preventScroll: true });
    };

    const react = (emoji: string) => {
        pendingReaction.current = emoji;
        setIsReservingReaction(!hasReactions);
        close();
    };

    useEffect(() => {
        if (isOpen || pendingReaction.current === null) {
            return;
        }

        const emoji = pendingReaction.current;
        pendingReaction.current = null;

        // Adding the chip or changing the presets would move the tray while it folds away.
        void Promise.allSettled(
            tray.current
                ?.getAnimations()
                .map((animation) => animation.finished) ?? [],
        ).then(() => {
            onReact(emoji);
            setIsReservingReaction(false);
        });
    }, [isOpen, onReact]);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        void preloadEmojiGroups();
        tray.current
            ?.querySelector<HTMLButtonElement>('button')
            ?.focus({ preventScroll: true });
    }, [isOpen]);

    // The full picker dismisses itself when something else is clicked; the tray only follows on the next click.
    useEffect(() => {
        if (!isOpen || isPicking) {
            return;
        }

        const onPointerDown = (event: globalThis.PointerEvent) => {
            const target = event.target as Node;

            if (
                tray.current?.contains(target) ||
                trigger.current?.contains(target)
            ) {
                return;
            }

            onOpenChange(false);
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [isOpen, isPicking, onOpenChange, trigger]);

    return (
        <div
            ref={tray}
            id={id}
            inert={!isOpen}
            data-state={isOpen ? 'open' : 'closed'}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && !isPicking) {
                    event.preventDefault();
                    close();
                }
            }}
            className={cn(
                'grid w-full duration-300 ease-[cubic-bezier(0.2,0.8,0.2,1)] motion-reduce:transition-none pointer-coarse:hidden',
                isOpen ? '-mb-2 grid-rows-[1fr]' : 'grid-rows-[0fr]',
                // The first chip replaces this 26px row (including bottom spacing) and its 2px gap.
                reservesFirstReaction
                    ? 'pb-7 transition-[grid-template-rows,margin-bottom,padding-bottom]'
                    : 'transition-[grid-template-rows,margin-bottom]',
                // Lower by half the room kept under the toolbar for its shadow, so the toolbar centres on the row.
                placement === 'beside' &&
                    cn(
                        'absolute top-[calc(50%+0.375rem)] right-full z-10 mr-1 mb-0 w-max -translate-y-1/2 grid-rows-[1fr] pb-0 transition-opacity',
                        { 'opacity-0': !isOpen },
                    ),
            )}
            data-test="reaction-tray"
        >
            {/* The fold clips, so it reaches past the row by the shadow's spread, and the open tray gives that back below. */}
            <div
                className={cn(
                    '-mx-2 flex min-h-0 items-start overflow-hidden px-2',
                    {
                        'justify-end': isMine,
                    },
                )}
            >
                <div
                    role="toolbar"
                    aria-label="React"
                    className={cn(
                        'mt-0.5 mb-3.5 flex w-max items-center gap-0.5 rounded-lg border bg-popover p-[3px] shadow-[0_6px_16px_-8px_rgb(0_0_0/0.35)] transition-[opacity,translate] duration-300 ease-[cubic-bezier(0.2,0.8,0.2,1)] motion-reduce:transition-none',
                        isOpen
                            ? 'translate-y-0 opacity-100'
                            : '-translate-y-2 opacity-0',
                    )}
                >
                    {quickReactions(usage).map((emoji, index) => (
                        <span
                            key={emoji}
                            style={{
                                transitionDelay: isOpen
                                    ? `${80 + index * 40}ms`
                                    : '0ms',
                            }}
                            className={cn(
                                'transition-[opacity,scale] duration-400 ease-spring motion-reduce:transition-none',
                                isOpen
                                    ? 'scale-100 opacity-100'
                                    : 'scale-50 opacity-0',
                            )}
                        >
                            <button
                                type="button"
                                aria-label={`React with ${shortcodeOf(emoji)}`}
                                aria-pressed={reacted.has(emoji)}
                                title={shortcodeOf(emoji)}
                                onClick={() => react(emoji)}
                                className={cn(
                                    'grid size-8 place-items-center rounded-md text-lg leading-none transition-[background-color,scale] hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:scale-90',
                                    { 'bg-primary-soft': reacted.has(emoji) },
                                )}
                                data-test="quick-reaction"
                            >
                                {emoji}
                            </button>
                        </span>
                    ))}
                    <span aria-hidden className="mx-0.5 h-4 w-px bg-border" />
                    <Popover open={isPicking} onOpenChange={setIsPickerOpen}>
                        <PopoverTrigger asChild>
                            <button
                                type="button"
                                aria-label="More reactions"
                                title="More reactions"
                                className="grid size-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none data-[state=open]:bg-accent data-[state=open]:text-foreground"
                                data-test="more-reactions"
                            >
                                <SmilePlus aria-hidden className="size-4" />
                            </button>
                        </PopoverTrigger>
                        <PopoverContent
                            align={isMine ? 'end' : 'start'}
                            collisionPadding={8}
                            className="flex h-[min(25rem,var(--radix-popover-content-available-height))] w-76 flex-col overflow-hidden rounded-lg p-0 shadow-[0_12px_32px_-12px_rgb(0_0_0/0.35)]"
                            onCloseAutoFocus={(event) => {
                                const emoji = pickedEmoji.current;

                                if (emoji !== null) {
                                    event.preventDefault();
                                    pickedEmoji.current = null;
                                    react(emoji);
                                }
                            }}
                        >
                            <EmojiPicker
                                usage={usage}
                                reacted={reacted}
                                onPick={(emoji) => {
                                    // Keep the fading picker's contents and anchor still until it unmounts.
                                    pickedEmoji.current = emoji;
                                    setIsPickerOpen(false);
                                }}
                            />
                        </PopoverContent>
                    </Popover>
                </div>
            </div>
        </div>
    );
}

/**
 * Searchable grid of every emoji: the user's most used first, then each group. Arrow keys move through it from the
 * search field and Enter reacts, as the composer's suggestions do.
 */
export function EmojiPicker({
    usage,
    reacted,
    onPick,
    isSheet = false,
}: {
    usage: ReactionUsage[];
    reacted: Set<string>;
    onPick: (emoji: string) => void;
    isSheet?: boolean;
}) {
    const id = useId();
    const groups = useEmojiGroups();
    const isSlow = useIsSlow(groups === null);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const body = useRef<HTMLDivElement>(null);
    const uses = useMemo(
        () => new Map(usage.map(({ emoji, count }) => [emoji, count])),
        [usage],
    );
    const isSearching = query.trim().replace(/:/g, '') !== '';

    const sections = useMemo<EmojiGroup[]>(() => {
        if (isSearching) {
            return [
                {
                    name: 'Results',
                    emoji: searchEmojiByUse(query, uses, 200).map(
                        (result) => result.emoji,
                    ),
                },
            ];
        }

        const frequent = frequentReactions(usage, columns);

        return [
            ...(frequent.length > 0
                ? [{ name: 'Frequently used', emoji: frequent }]
                : []),
            ...(groups ?? []),
        ];
    }, [isSearching, query, uses, usage, groups]);

    const all = useMemo(
        () => sections.flatMap((section) => section.emoji),
        [sections],
    );
    const active = all[Math.min(activeIndex, all.length - 1)] as
        | string
        | undefined;
    const cellId = (index: number) => `${id}-emoji-${index}`;

    const move = (to: number) => {
        const next = Math.max(0, Math.min(all.length - 1, to));

        setActiveIndex(next);
        document
            .getElementById(cellId(next))
            ?.scrollIntoView({ block: 'nearest' });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        const moves: Record<string, number> = {
            ArrowRight: 1,
            ArrowLeft: -1,
            ArrowDown: columns,
            ArrowUp: -columns,
        };

        if (event.key in moves && all.length > 0) {
            event.preventDefault();
            move(activeIndex + moves[event.key]);
        } else if (event.key === 'Enter' && active !== undefined) {
            event.preventDefault();
            onPick(active);
        }
    };

    let offset = 0;

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-test="emoji-picker">
            <label
                className={cn(
                    'mx-2 mt-2 mb-1 flex h-9 shrink-0 items-center gap-2 rounded-lg border bg-background px-2.5 text-muted-foreground focus-within:border-ring',
                    { 'mx-3': isSheet },
                )}
            >
                <Search aria-hidden className="size-4 shrink-0" />
                <input
                    type="search"
                    autoFocus={!isSheet}
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActiveIndex(0);
                        body.current?.scrollTo({ top: 0 });
                    }}
                    onKeyDown={onKeyDown}
                    placeholder="Search emoji"
                    aria-label="Search emoji"
                    aria-controls={`${id}-emoji`}
                    aria-activedescendant={
                        active === undefined
                            ? undefined
                            : cellId(Math.min(activeIndex, all.length - 1))
                    }
                    autoComplete="off"
                    spellCheck={false}
                    className="min-w-0 flex-1 bg-transparent text-base text-foreground outline-none placeholder:text-muted-foreground md:text-sm [&::-webkit-search-cancel-button]:hidden"
                    data-test="emoji-search"
                />
            </label>

            <div
                ref={body}
                id={`${id}-emoji`}
                role="listbox"
                aria-label="Emoji"
                className="min-h-0 flex-1 scrollbar-thin overflow-y-auto overscroll-contain px-1.5 pb-1.5"
            >
                {isSearching && all.length === 0 && (
                    <p className="px-2 py-8 text-center font-mono text-[13px] text-muted-foreground">
                        no emoji for “{query.trim()}”
                    </p>
                )}
                {sections.map((section) => {
                    const start = offset;
                    offset += section.emoji.length;

                    return (
                        <div
                            key={section.name}
                            role="group"
                            aria-label={section.name}
                        >
                            {!isSearching && (
                                <div className="sticky top-0 z-10 bg-popover px-1.5 pt-2 pb-1 text-[11px] font-medium text-muted-foreground">
                                    {section.name}
                                </div>
                            )}
                            <div
                                className={cn('grid grid-cols-8', {
                                    'pt-1': isSearching,
                                })}
                            >
                                {section.emoji.map((emoji, index) => (
                                    <EmojiCell
                                        key={emoji}
                                        id={cellId(start + index)}
                                        emoji={emoji}
                                        isActive={
                                            start + index ===
                                            Math.min(
                                                activeIndex,
                                                all.length - 1,
                                            )
                                        }
                                        isReacted={reacted.has(emoji)}
                                        isSheet={isSheet}
                                        onHover={() =>
                                            setActiveIndex(start + index)
                                        }
                                        onPick={() => onPick(emoji)}
                                    />
                                ))}
                            </div>
                        </div>
                    );
                })}
                {!isSearching && groups === null && isSlow && (
                    <div aria-hidden className="grid grid-cols-8 gap-1 p-1.5">
                        {Array.from({ length: 40 }, (_, index) => (
                            <Skeleton key={index} className="aspect-square" />
                        ))}
                    </div>
                )}
            </div>

            <div
                aria-live="polite"
                className="flex h-11 shrink-0 items-center gap-2.5 border-t px-3"
            >
                {active !== undefined && (
                    <>
                        <span aria-hidden className="text-2xl leading-none">
                            {active}
                        </span>
                        <span className="min-w-0 flex-1 truncate font-mono text-[13px]">
                            {shortcodeOf(active)}
                        </span>
                        {(uses.get(active) ?? 0) > 0 && (
                            <span className="shrink-0 font-mono text-[11px] text-muted-foreground tabular-nums">
                                used {uses.get(active)}×
                            </span>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

function EmojiCell({
    id,
    emoji,
    isActive,
    isReacted,
    isSheet,
    onHover,
    onPick,
}: {
    id: string;
    emoji: string;
    isActive: boolean;
    isReacted: boolean;
    isSheet: boolean;
    onHover: () => void;
    onPick: () => void;
}) {
    return (
        <div
            id={id}
            role="option"
            aria-selected={isActive}
            aria-label={shortcodeOf(emoji)}
            title={shortcodeOf(emoji)}
            onMouseMove={isActive ? undefined : onHover}
            onClick={onPick}
            className={cn(
                'grid aspect-square cursor-pointer place-items-center rounded-md text-xl leading-none select-none',
                {
                    'bg-accent': isActive && !isSheet && !isReacted,
                    'bg-primary-soft': isReacted,
                    'ring-1 ring-primary/60 ring-inset':
                        isActive && !isSheet && isReacted,
                    'text-2xl': isSheet,
                },
            )}
            data-test="emoji-option"
        >
            {emoji}
        </div>
    );
}

/**
 * Pressing and holding a message on a touch screen, and tapping it twice. Moving the finger, as scrolling or swiping
 * to reply does, cancels either, taps on links and buttons in the message don't count towards a double tap, and the
 * tap that ends a long press doesn't also follow a link.
 */
export function useTouchReactions(
    gestures: { onLongPress: () => void; onDoubleTap?: () => void } | null,
) {
    const timer = useRef<number | undefined>(undefined);
    const start = useRef<{ x: number; y: number } | null>(null);
    const lastTap = useRef<Tap | null>(null);
    const didLongPress = useRef(false);
    const didDoubleTap = useRef(false);

    const cancel = () => {
        window.clearTimeout(timer.current);
        start.current = null;
    };

    useEffect(() => () => window.clearTimeout(timer.current), []);

    if (gestures === null) {
        return {};
    }

    return {
        onPointerDown: (event: PointerEvent) => {
            if (event.pointerType !== 'touch') {
                return;
            }

            didLongPress.current = false;

            // Portaled viewers still bubble through React, and a second finger starts a pinch, not a hold.
            if (
                !event.currentTarget.contains(event.target as Node) ||
                !event.isPrimary
            ) {
                cancel();
                lastTap.current = null;

                return;
            }

            start.current = { x: event.clientX, y: event.clientY };
            timer.current = window.setTimeout(() => {
                didLongPress.current = true;
                start.current = null;
                lastTap.current = null;
                navigator.vibrate?.(8);
                gestures.onLongPress();
            }, longPressDelay);
        },
        onPointerMove: (event: PointerEvent) => {
            if (
                start.current !== null &&
                Math.hypot(
                    event.clientX - start.current.x,
                    event.clientY - start.current.y,
                ) > 10
            ) {
                cancel();
                lastTap.current = null;
            }
        },
        onPointerUp: (event: PointerEvent) => {
            const isTap =
                start.current !== null &&
                !(event.target as Element).closest('a, button, input, img');

            cancel();

            if (!isTap) {
                return;
            }

            const tap = {
                x: event.clientX,
                y: event.clientY,
                time: event.timeStamp,
            };

            if (isDoubleTap(lastTap.current, tap)) {
                lastTap.current = null;
                didDoubleTap.current = true;
                navigator.vibrate?.(8);
                gestures.onDoubleTap?.();
            } else {
                lastTap.current = tap;
            }
        },
        onPointerCancel: () => {
            cancel();
            lastTap.current = null;
        },
        // The browser would follow a double tap with mouse events that select the word under the finger.
        onTouchEnd: (event: { preventDefault: () => void }) => {
            if (didDoubleTap.current) {
                didDoubleTap.current = false;
                event.preventDefault();
            }
        },
        onContextMenu: (event: { preventDefault: () => void }) => {
            if (start.current !== null || didLongPress.current) {
                event.preventDefault();
            }
        },
        onClickCapture: (event: {
            preventDefault: () => void;
            stopPropagation: () => void;
        }) => {
            if (didLongPress.current) {
                didLongPress.current = false;
                event.preventDefault();
                event.stopPropagation();
            }
        },
    };
}

/**
 * What a long press opens on a touch screen: the message lifted above the thread with the quick reactions over it,
 * and the full picker in a sheet from the bottom. Maintainers can pin or unpin the message from it too.
 */
export function ReactionSheet({
    isOpen,
    onOpenChange,
    usage,
    reacted,
    isMine,
    body,
    preview,
    onReact,
    pin = null,
}: {
    isOpen: boolean;
    onOpenChange: (isOpen: boolean) => void;
    usage: ReactionUsage[];
    reacted: Set<string>;
    isMine: boolean;
    body: string;
    preview: ReactNode;
    onReact: (emoji: string) => void;
    pin?: { isPinned: boolean; onToggle: () => void } | null;
}) {
    const [isPicking, setIsPicking] = useState(false);
    const [isReleased, setIsReleased] = useState(false);
    const isHeld = isOpen && !isReleased;
    const isArmed = useRef(false);

    // The finger that long pressed is still down when the sheet opens, wherever its controls land under it.
    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const release = () => setIsReleased(true);

        window.addEventListener('pointerup', release, { once: true });
        window.addEventListener('pointercancel', release, { once: true });

        return () => {
            window.removeEventListener('pointerup', release);
            window.removeEventListener('pointercancel', release);
        };
    }, [isOpen]);

    const close = () => {
        onOpenChange(false);
        setIsPicking(false);
        setIsReleased(false);
    };

    const react = (emoji: string) => {
        close();
        onReact(emoji);
    };

    return (
        <DialogPrimitive.Root
            open={isOpen}
            onOpenChange={(open) => {
                if (!open) {
                    close();
                }
            }}
        >
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-background/70 backdrop-blur-sm data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
                <DialogPrimitive.Content
                    aria-describedby={undefined}
                    onOpenAutoFocus={(event) => {
                        event.preventDefault();
                        isArmed.current = false;

                        // A long press while typing puts the keyboard away, so the sheet has the screen.
                        if (document.activeElement instanceof HTMLElement) {
                            document.activeElement.blur();
                        }
                    }}
                    // The finger that long pressed lifts over the sheet, which must not count as a tap on it.
                    onPointerDownCapture={() => {
                        isArmed.current = true;
                    }}
                    onClickCapture={(event) => {
                        if (!isArmed.current && event.detail > 0) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                    }}
                    // The content covers the screen, so a tap anywhere but the message and its controls closes it.
                    onClick={(event) => {
                        if (
                            !(event.target as Element).closest(
                                '[data-sheet-part]',
                            )
                        ) {
                            close();
                        }
                    }}
                    className={cn(
                        'fixed inset-x-0 top-[var(--visual-viewport-top,0px)] z-50 flex h-[var(--visual-viewport-height,100dvh)] flex-col px-3 pt-[max(1rem,env(safe-area-inset-top))] outline-none select-none [-webkit-touch-callout:none]',
                        { '[&_[data-sheet-part]]:pointer-events-none': isHeld },
                    )}
                    data-test="reaction-sheet"
                >
                    <DialogPrimitive.Title className="sr-only">
                        React to the message
                    </DialogPrimitive.Title>
                    <div
                        className={cn(
                            'flex min-h-0 flex-col gap-2 transition-[flex-grow] duration-300 ease-out motion-safe:animate-in motion-safe:fade-in-0 motion-safe:zoom-in-95',
                            isPicking ? 'grow-0' : 'grow justify-center',
                            { 'items-end': isMine },
                        )}
                    >
                        <div
                            role="toolbar"
                            aria-label="React"
                            data-sheet-part
                            className="flex w-max items-center gap-1 rounded-lg border bg-popover p-1 shadow-[0_10px_28px_-10px_rgb(0_0_0/0.45)]"
                        >
                            {quickReactions(usage).map((emoji) => (
                                <button
                                    key={emoji}
                                    type="button"
                                    aria-label={`React with ${shortcodeOf(emoji)}`}
                                    aria-pressed={reacted.has(emoji)}
                                    onClick={() => react(emoji)}
                                    className={cn(
                                        'grid size-11 place-items-center rounded-md text-2xl leading-none transition-transform active:scale-90',
                                        {
                                            'bg-primary-soft':
                                                reacted.has(emoji),
                                        },
                                    )}
                                >
                                    {emoji}
                                </button>
                            ))}
                            <span
                                aria-hidden
                                className="mx-0.5 h-6 w-px bg-border"
                            />
                            <button
                                type="button"
                                aria-label="More reactions"
                                aria-expanded={isPicking}
                                onClick={() => setIsPicking(true)}
                                className={cn(
                                    'grid size-11 place-items-center rounded-md text-muted-foreground',
                                    { 'bg-accent text-foreground': isPicking },
                                )}
                            >
                                <SmilePlus aria-hidden className="size-5" />
                            </button>
                        </div>
                        <div
                            data-sheet-part
                            className="max-h-[30dvh] max-w-[85%] overflow-hidden"
                        >
                            {preview}
                        </div>
                        {body !== '' && !isPicking && (
                            <button
                                type="button"
                                onClick={() => {
                                    void navigator.clipboard
                                        .writeText(body)
                                        .then(() => toast.success('Copied.'));
                                    close();
                                }}
                                data-sheet-part
                                className="flex w-max items-center gap-2 rounded-lg border bg-popover px-3 py-2 text-sm shadow-sm"
                            >
                                <Copy aria-hidden className="size-4" />
                                Copy text
                            </button>
                        )}
                        {pin !== null && !isPicking && (
                            <button
                                type="button"
                                onClick={() => {
                                    close();
                                    pin.onToggle();
                                }}
                                data-sheet-part
                                className="flex w-max items-center gap-2 rounded-lg border bg-popover px-3 py-2 text-sm shadow-sm"
                                data-test="sheet-pin"
                            >
                                {pin.isPinned ? (
                                    <PinOff aria-hidden className="size-4" />
                                ) : (
                                    <Pin aria-hidden className="size-4" />
                                )}
                                {pin.isPinned ? 'Unpin message' : 'Pin message'}
                            </button>
                        )}
                    </div>
                    {isPicking && (
                        <PickerSheet onCollapse={() => setIsPicking(false)}>
                            <EmojiPicker
                                usage={usage}
                                reacted={reacted}
                                onPick={react}
                                isSheet
                            />
                        </PickerSheet>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

/**
 * How far the sheet is pulled down before letting go hides it.
 */
const collapseDistance = 80;

/**
 * The emoji sheet from the bottom of the screen. Pulling its handle down, or tapping it, hides the sheet again and
 * goes back to the quick reactions.
 */
function PickerSheet({
    onCollapse,
    children,
}: {
    onCollapse: () => void;
    children: ReactNode;
}) {
    const [offset, setOffset] = useState(0);
    const [isDragging, setIsDragging] = useState(false);
    const start = useRef<number | null>(null);

    const release = () => {
        if (start.current === null) {
            return;
        }

        start.current = null;
        setIsDragging(false);

        if (offset >= collapseDistance) {
            onCollapse();
        }

        setOffset(0);
    };

    return (
        <div
            data-sheet-part
            style={
                offset > 0
                    ? { transform: `translateY(${offset}px)` }
                    : undefined
            }
            className={cn(
                '-mx-3 mt-auto flex h-[min(calc(var(--visual-viewport-height,100dvh)*0.6),28rem)] flex-col rounded-t-lg border-t bg-popover pb-[env(safe-area-inset-bottom)] shadow-[0_-12px_30px_-16px_rgb(0_0_0/0.45)] motion-safe:animate-in motion-safe:slide-in-from-bottom',
                { 'transition-transform duration-200': !isDragging },
            )}
            data-test="emoji-sheet"
        >
            <button
                type="button"
                aria-label="Hide emoji picker"
                onPointerDown={(event) => {
                    event.currentTarget.setPointerCapture(event.pointerId);
                    start.current = event.clientY;
                    setIsDragging(true);
                }}
                onPointerMove={(event) => {
                    if (start.current !== null) {
                        setOffset(Math.max(0, event.clientY - start.current));
                    }
                }}
                onPointerUp={() => {
                    if (offset < 4) {
                        start.current = null;
                        setIsDragging(false);
                        onCollapse();

                        return;
                    }

                    release();
                }}
                onPointerCancel={release}
                onClick={(event) => {
                    if (event.detail === 0) {
                        onCollapse();
                    }
                }}
                className="flex h-7 w-full shrink-0 touch-none items-center justify-center"
                data-test="emoji-sheet-handle"
            >
                <span aria-hidden className="h-1 w-9 rounded-full bg-border" />
            </button>
            {children}
        </div>
    );
}
