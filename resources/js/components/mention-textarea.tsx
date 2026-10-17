import type { ComponentProps, KeyboardEvent, Ref, SyntheticEvent } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import MentionSuggestionController from '@/actions/App/Http/Controllers/MentionSuggestionController';
import {
    CaretMenuList,
    CaretMenuPortal,
    handleCaretMenuKeydown,
} from '@/components/caret-menu';
import { Spinner } from '@/components/ui/spinner';
import { UserAvatar } from '@/components/user-avatar';
import { caretMenuPosition } from '@/lib/caret-position';
import type { CaretMenuPosition } from '@/lib/caret-position';
import { caretToken } from '@/lib/caret-token';
import type { CaretToken } from '@/lib/caret-token';
import { typedProse } from '@/lib/code';
import { emojifyInput, searchEmoji } from '@/lib/emoji';
import type { EmojiSuggestion } from '@/lib/emoji';
import {
    availableCommands,
    codeFenceInsertion,
    consumeSlashToken,
    filterCommands,
    matchCommand,
} from '@/lib/slash-commands';
import type { ComposerSurface, SlashOption } from '@/lib/slash-commands';
import { toastWhenThrottled } from '@/lib/throttle';
import { characterCount, cn, laneColor, limitCharacters } from '@/lib/utils';
import type { User } from '@/types';

type Suggestions = {
    query: string;
    users: User[];
};

const debounce = 120;
const menuWidth = 288;
const mentionMenuHeight = 320;
const slashMenuHeight = 168;
const emojiMenuHeight = 320;
const mentionMenu = { width: menuWidth, height: mentionMenuHeight };
const slashMenu = { width: menuWidth, height: slashMenuHeight };
const emojiMenu = { width: menuWidth, height: emojiMenuHeight };
const cache = new Map<string, User[]>();

async function fetchSuggestions(
    query: string,
    signal: AbortSignal,
): Promise<User[]> {
    const response = await fetch(
        MentionSuggestionController.index({ query: { q: query } }).url,
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal,
        },
    );

    if (!response.ok) {
        toastWhenThrottled(response);

        throw new Error('Unable to load suggestions');
    }

    const data = (await response.json()) as { users: User[] };
    cache.set(query.toLowerCase(), data.users);

    return data.users;
}

/**
 * Where the query matches the text, preferring the start of a word, so ":art" marks "arts" in "martial_arts".
 */
function matchIndex(text: string, query: string): number {
    const lower = text.toLowerCase();
    const first = query === '' ? -1 : lower.indexOf(query);

    for (let at = first; at !== -1; at = lower.indexOf(query, at + 1)) {
        if (at === 0 || /[^\p{L}\p{N}]/u.test(lower[at - 1])) {
            return at;
        }
    }

    return first;
}

function Highlighted({ text, query }: { text: string; query: string }) {
    const index = matchIndex(text, query);

    if (index === -1) {
        return text;
    }

    return (
        <>
            {text.slice(0, index)}
            <span className="text-foreground">
                {text.slice(index, index + query.length)}
            </span>
            {text.slice(index + query.length)}
        </>
    );
}

/**
 * A textarea that suggests developers to mention as soon as "@" is typed, right under the "@", suggests emoji for the
 * ":shortcode" being typed, suggests slash commands as soon as "/" is typed, and turns emoji shortcodes into emoji as
 * they are typed.
 */
export function MentionTextarea({
    ref,
    value,
    onValueChange,
    onKeyDown,
    onSelect,
    onBlur,
    onImage,
    onPoll,
    commandContext,
    maxLength,
    ...props
}: Omit<ComponentProps<'textarea'>, 'value' | 'onChange'> & {
    ref?: Ref<HTMLTextAreaElement>;
    value: string;
    onValueChange: (value: string) => void;
    onImage?: () => void;
    onPoll?: () => void;
    commandContext?: ComposerSurface;
}) {
    const id = useId();
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const [token, setToken] = useState<CaretToken | null>(null);
    const [dismissedStart, setDismissedStart] = useState<number | null>(null);
    const [position, setPosition] = useState<CaretMenuPosition | null>(null);
    const [results, setResults] = useState<Suggestions | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);

    const mention = token?.kind === 'mention' ? token : null;
    const slash = token?.kind === 'slash' ? token : null;
    const emoji = token?.kind === 'emoji' ? token : null;
    const query = mention?.query.toLowerCase() ?? null;

    const setRefs = (element: HTMLTextAreaElement | null) => {
        textareaRef.current = element;

        if (typeof ref === 'function') {
            ref(element);
        } else if (ref) {
            ref.current = element;
        }
    };

    const close = () => {
        setToken(null);
        setPosition(null);
    };

    const closeSlash = () => {
        if (slash !== null) {
            setDismissedStart(slash.start);
        }

        close();
    };

    const track = (textarea: HTMLTextAreaElement) => {
        const caret = textarea.selectionStart;
        const next = caretToken(
            textarea,
            typedProse(textarea.value.slice(0, caret)).at(-1) ?? '',
        );

        if (next === null) {
            setDismissedStart(null);
            close();

            return;
        }

        if (
            next.kind !== token?.kind ||
            next.start !== token?.start ||
            next.query !== token.query
        ) {
            setActiveIndex(0);
        }

        if (next.kind === 'slash') {
            if (commandContext === undefined || next.start === dismissedStart) {
                close();

                return;
            }

            setToken(next);
            setPosition(caretMenuPosition(textarea, next.start, slashMenu));

            return;
        }

        setToken(next);
        setPosition(
            caretMenuPosition(
                textarea,
                next.start,
                next.kind === 'mention' ? mentionMenu : emojiMenu,
            ),
        );
    };

    useEffect(() => {
        if (dismissedStart !== null && value[dismissedStart] !== '/') {
            // oxlint-disable-next-line react/set-state-in-effect
            setDismissedStart(null);
        }
    }, [value, dismissedStart]);

    useEffect(() => {
        if (query === null || cache.has(query)) {
            return;
        }

        const controller = new AbortController();
        const timeout = setTimeout(
            () =>
                fetchSuggestions(query, controller.signal)
                    .then((users) => setResults({ query, users }))
                    .catch(() => undefined),
            query === '' ? 0 : debounce,
        );

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [query]);

    useEffect(() => {
        if (token === null) {
            return;
        }

        const menu =
            token.kind === 'mention'
                ? mentionMenu
                : token.kind === 'slash'
                  ? slashMenu
                  : emojiMenu;

        const reposition = () => {
            if (textareaRef.current) {
                setPosition(
                    caretMenuPosition(textareaRef.current, token.start, menu),
                );
            }
        };

        window.addEventListener('scroll', reposition, {
            capture: true,
            passive: true,
        });
        window.addEventListener('resize', reposition);

        return () => {
            window.removeEventListener('scroll', reposition, {
                capture: true,
            });
            window.removeEventListener('resize', reposition);
        };
    }, [token]);

    const cached = query === null ? undefined : cache.get(query);
    const current =
        query !== null && cached ? { query, users: cached } : results;
    const suggestions =
        query !== null && current?.query === query ? current.users : [];
    const isOpen =
        mention !== null &&
        position !== null &&
        (suggestions.length > 0 || query !== '');
    const slashOptions =
        slash !== null && commandContext !== undefined
            ? filterCommands(
                  availableCommands(commandContext),
                  slash.query,
              ).filter(
                  (option) =>
                      (option.command.id !== 'image' ||
                          onImage !== undefined) &&
                      (option.command.id !== 'poll' || onPoll !== undefined),
              )
            : [];
    const isSlashOpen =
        slash !== null && position !== null && slashOptions.length > 0;
    const slashIndex =
        slashOptions.length > 0
            ? Math.min(activeIndex, slashOptions.length - 1)
            : 0;
    const emojiQuery = emoji?.query.toLowerCase() ?? '';
    const emojis = emoji === null ? [] : searchEmoji(emojiQuery);
    const isEmojiOpen =
        emoji !== null && position !== null && emojis.length > 0;

    const insert = (text: string, separator: string) => {
        const textarea = textareaRef.current;

        if (!textarea || token === null) {
            return;
        }

        const caret = token.start + 1 + token.query.length;
        const after = value.slice(caret);
        const spacing = /^\s/u.test(after) ? '' : separator;
        let next = `${value.slice(0, token.start)}${text}${spacing}${after}`;

        if (maxLength !== undefined && characterCount(next) > maxLength) {
            next = `${value.slice(0, token.start)}${text}${after}`;
        }

        if (maxLength !== undefined && characterCount(next) > maxLength) {
            close();

            return;
        }

        const nextCaret = token.start + text.length + separator.length;

        onValueChange(next);
        close();

        requestAnimationFrame(() => {
            textarea.focus();
            textarea.setSelectionRange(
                Math.min(nextCaret, next.length),
                Math.min(nextCaret, next.length),
            );
        });
    };

    const select = (user: User) => insert(`@${user.username}`, ' ');

    const selectEmoji = (suggestion: EmojiSuggestion) =>
        insert(suggestion.emoji, '');

    const runSlash = (option: SlashOption): boolean => {
        if (slash === null || !option.enabled) {
            return false;
        }

        if (option.command.id === 'code') {
            const insertion = codeFenceInsertion(
                value,
                slash.start,
                slash.start + slash.query.length + 1,
            );

            if (
                maxLength !== undefined &&
                characterCount(insertion.value) > maxLength
            ) {
                return false;
            }

            onValueChange(insertion.value);
            close();

            requestAnimationFrame(() => {
                const textarea = textareaRef.current;

                if (textarea) {
                    textarea.focus();
                    textarea.setSelectionRange(
                        insertion.caret,
                        insertion.caret,
                    );
                }
            });

            return true;
        }

        onValueChange(consumeSlashToken(value, slash.start, slash.query));
        close();

        requestAnimationFrame(() => textareaRef.current?.focus());

        if (option.command.id === 'image') {
            onImage?.();
        }

        if (option.command.id === 'poll') {
            onPoll?.();
        }

        return true;
    };

    const slashOptionFits = (index: number) => {
        const option = slashOptions[index];

        if (
            option === undefined ||
            option.command.id !== 'code' ||
            maxLength === undefined ||
            slash === null
        ) {
            return true;
        }

        const insertion = codeFenceInsertion(
            value,
            slash.start,
            slash.start + slash.query.length + 1,
        );

        return characterCount(insertion.value) <= maxLength;
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (
            isSlashOpen &&
            handleCaretMenuKeydown(event, {
                count: slashOptions.length,
                activeIndex: slashIndex,
                setActiveIndex,
                canSelect: slashOptionFits,
                onSelect: (index) => {
                    const option = slashOptions[index];

                    if (option) {
                        runSlash(option);
                    }
                },
                onClose: closeSlash,
            })
        ) {
            return;
        }

        if (
            slash !== null &&
            event.key === ' ' &&
            !event.nativeEvent.isComposing
        ) {
            const exact = matchCommand(slash.query);
            const option = exact
                ? slashOptions.find(
                      (candidate) => candidate.command.id === exact.id,
                  )
                : undefined;

            if (option?.enabled && runSlash(option)) {
                event.preventDefault();

                return;
            }
        }

        if (
            isOpen &&
            handleCaretMenuKeydown(event, {
                count: suggestions.length,
                activeIndex,
                setActiveIndex,
                onSelect: (index) => {
                    const user = suggestions[index];

                    if (user) {
                        select(user);
                    }
                },
                onClose: close,
            })
        ) {
            return;
        }

        if (
            isEmojiOpen &&
            handleCaretMenuKeydown(event, {
                count: emojis.length,
                activeIndex,
                setActiveIndex,
                onSelect: (index) => {
                    const suggestion = emojis[index];

                    if (suggestion) {
                        selectEmoji(suggestion);
                    }
                },
                onClose: close,
            })
        ) {
            return;
        }

        onKeyDown?.(event);
    };

    const activeId = isOpen
        ? suggestions[activeIndex] &&
          `${id}-mention-${suggestions[activeIndex].id}`
        : isSlashOpen && slashOptions[slashIndex]
          ? `${id}-slash-${slashOptions[slashIndex].command.id}`
          : isEmojiOpen && emojis[activeIndex]
            ? `${id}-emoji-${emojis[activeIndex].name}`
            : undefined;

    const isSearching = current?.query !== query;

    return (
        <>
            <textarea
                {...props}
                ref={setRefs}
                value={value}
                onChange={(event) => {
                    const target = event.currentTarget;
                    const { text, caret } = emojifyInput(
                        target.value,
                        target.selectionStart ?? target.value.length,
                    );

                    const limited =
                        maxLength === undefined
                            ? text
                            : limitCharacters(text, maxLength);

                    onValueChange(limited);

                    if (limited === target.value) {
                        track(target);

                        return;
                    }

                    close();
                    requestAnimationFrame(() => {
                        const position = Math.min(caret, limited.length);
                        target.setSelectionRange(position, position);
                    });
                }}
                onSelect={(event: SyntheticEvent<HTMLTextAreaElement>) => {
                    track(event.currentTarget);
                    onSelect?.(event);
                }}
                onBlur={(event) => {
                    close();
                    onBlur?.(event);
                }}
                onKeyDown={handleKeyDown}
                aria-autocomplete="list"
                aria-controls={
                    isOpen
                        ? `${id}-mentions`
                        : isSlashOpen
                          ? `${id}-slash`
                          : isEmojiOpen
                            ? `${id}-emojis`
                            : undefined
                }
                aria-activedescendant={activeId}
            />

            {isOpen && position && (
                <CaretMenuPortal
                    marker="mention"
                    position={position}
                    testId="mention-suggestions"
                >
                    {suggestions.length > 0 ? (
                        <CaretMenuList
                            id={`${id}-mentions`}
                            label="Mention a developer"
                            activeIndex={activeIndex}
                        >
                            {current?.query === '' && (
                                <li
                                    role="presentation"
                                    className="px-2.5 pt-1 pb-1.5 text-xs text-muted-foreground"
                                >
                                    People you follow
                                </li>
                            )}
                            {suggestions.map((user, index) => (
                                <li
                                    key={user.id}
                                    id={`${id}-mention-${user.id}`}
                                    role="option"
                                    aria-selected={index === activeIndex}
                                    onMouseMove={() => setActiveIndex(index)}
                                    onClick={() => select(user)}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2',
                                        {
                                            'bg-accent': index === activeIndex,
                                        },
                                    )}
                                    data-test="mention-suggestion"
                                >
                                    <UserAvatar
                                        user={user}
                                        className="size-8 shrink-0"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold">
                                            <Highlighted
                                                text={user.name}
                                                query={query ?? ''}
                                            />
                                        </span>
                                        <span className="flex items-center gap-1 font-mono text-xs text-muted-foreground">
                                            <span
                                                aria-hidden
                                                className="size-1.5 shrink-0 rounded-full"
                                                style={{
                                                    background: laneColor(
                                                        user.lane,
                                                    ),
                                                }}
                                            />
                                            <span className="truncate">
                                                @
                                                <Highlighted
                                                    text={user.username}
                                                    query={query ?? ''}
                                                />
                                            </span>
                                        </span>
                                    </span>
                                    <kbd
                                        aria-hidden
                                        className={cn(
                                            'hidden shrink-0 font-mono text-xs text-muted-foreground opacity-0 sm:block',
                                            {
                                                'opacity-100':
                                                    index === activeIndex,
                                            },
                                        )}
                                    >
                                        ↵
                                    </kbd>
                                </li>
                            ))}
                        </CaretMenuList>
                    ) : (
                        <div
                            role="status"
                            className="px-4 py-3 text-sm text-muted-foreground"
                        >
                            {isSearching ? (
                                <span className="flex items-center gap-2">
                                    <Spinner className="size-3.5" />
                                    Searching…
                                </span>
                            ) : (
                                <span className="font-mono text-[13px]">
                                    fatal: no developers match '{mention?.query}
                                    '
                                </span>
                            )}
                        </div>
                    )}
                </CaretMenuPortal>
            )}

            {isSlashOpen && position && (
                <CaretMenuPortal
                    marker="slash"
                    position={position}
                    testId="slash-suggestions"
                >
                    <CaretMenuList
                        id={`${id}-slash`}
                        label="Slash commands"
                        activeIndex={slashIndex}
                    >
                        {slashOptions.map((option, index) => {
                            const Icon = option.command.icon;

                            return (
                                <li
                                    key={option.command.id}
                                    id={`${id}-slash-${option.command.id}`}
                                    role="option"
                                    aria-selected={index === slashIndex}
                                    aria-disabled={
                                        option.enabled ? undefined : true
                                    }
                                    onMouseMove={() => setActiveIndex(index)}
                                    onClick={() => runSlash(option)}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2',
                                        {
                                            'bg-accent':
                                                index === slashIndex &&
                                                option.enabled,
                                            'cursor-not-allowed opacity-60':
                                                !option.enabled,
                                        },
                                    )}
                                    data-test="slash-command"
                                >
                                    <Icon
                                        aria-hidden
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold">
                                            {`/${option.command.id}`}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {option.reason ??
                                                option.command.description}
                                        </span>
                                    </span>
                                </li>
                            );
                        })}
                    </CaretMenuList>
                </CaretMenuPortal>
            )}

            {isEmojiOpen && position && (
                <CaretMenuPortal
                    marker="emoji"
                    position={position}
                    testId="emoji-suggestions"
                >
                    <CaretMenuList
                        id={`${id}-emojis`}
                        label="Insert an emoji"
                        activeIndex={activeIndex}
                    >
                        {emojis.map((suggestion, index) => (
                            <li
                                key={suggestion.name}
                                id={`${id}-emoji-${suggestion.name}`}
                                role="option"
                                aria-selected={index === activeIndex}
                                onMouseMove={() => setActiveIndex(index)}
                                onClick={() => selectEmoji(suggestion)}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-1.5',
                                    {
                                        'bg-accent': index === activeIndex,
                                    },
                                )}
                                data-test="emoji-suggestion"
                            >
                                <span
                                    aria-hidden
                                    className="w-6 shrink-0 text-center text-lg leading-none"
                                >
                                    {suggestion.emoji}
                                </span>
                                <span className="min-w-0 flex-1 truncate font-mono text-[13px] text-muted-foreground">
                                    :
                                    <Highlighted
                                        text={suggestion.name}
                                        query={emojiQuery}
                                    />
                                    :
                                </span>
                                <kbd
                                    aria-hidden
                                    className={cn(
                                        'hidden shrink-0 font-mono text-xs text-muted-foreground opacity-0 sm:block',
                                        {
                                            'opacity-100':
                                                index === activeIndex,
                                        },
                                    )}
                                >
                                    ↵
                                </kbd>
                            </li>
                        ))}
                    </CaretMenuList>
                </CaretMenuPortal>
            )}
        </>
    );
}
