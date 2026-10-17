import type { ComponentProps, KeyboardEvent, Ref, SyntheticEvent } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import MentionSuggestionController from '@/actions/App/Http/Controllers/MentionSuggestionController';
import { Spinner } from '@/components/ui/spinner';
import { UserAvatar } from '@/components/user-avatar';
import { emojifyInput } from '@/lib/emoji';
import { toastWhenThrottled } from '@/lib/throttle';
import { characterCount, cn, laneColor, limitCharacters } from '@/lib/utils';
import type { User } from '@/types';

type Mention = {
    start: number;
    query: string;
};

type Suggestions = {
    query: string;
    users: User[];
};

type Position = {
    left: number;
    top?: number;
    bottom?: number;
};

const debounce = 120;
const menuWidth = 288;
const menuHeight = 320;
const cache = new Map<string, User[]>();

export function clearMentionSuggestionsCache(): void {
    cache.clear();
}

// The "@handle" being typed right before the caret. Keep the rules in sync with app/Services/Mentions.php.
const mentionBeforeCaretPattern =
    /(?:^|[^\p{L}\p{N}_./@-])@([A-Za-z0-9][A-Za-z0-9-]{0,38}|)$/u;

// Every style that changes where text wraps, so the mirror below wraps exactly like the textarea.
const mirroredProperties = [
    'box-sizing',
    'width',
    'border-top-width',
    'border-right-width',
    'border-bottom-width',
    'border-left-width',
    'padding-top',
    'padding-right',
    'padding-bottom',
    'padding-left',
    'font-family',
    'font-size',
    'font-weight',
    'font-style',
    'font-stretch',
    'font-feature-settings',
    'font-variant',
    'line-height',
    'letter-spacing',
    'word-spacing',
    'text-transform',
    'text-indent',
    'tab-size',
];

function mentionAt(textarea: HTMLTextAreaElement): Mention | null {
    if (textarea.selectionStart !== textarea.selectionEnd) {
        return null;
    }

    const match = textarea.value
        .slice(0, textarea.selectionStart)
        .match(mentionBeforeCaretPattern);

    if (!match) {
        return null;
    }

    return {
        start: textarea.selectionStart - match[1].length - 1,
        query: match[1],
    };
}

/**
 * Where a character of the textarea is drawn, measured on a hidden copy of it that wraps the same way.
 */
function characterRect(textarea: HTMLTextAreaElement, index: number) {
    const style = window.getComputedStyle(textarea);
    const mirror = document.createElement('div');
    const marker = document.createElement('span');

    mirroredProperties.forEach((property) =>
        mirror.style.setProperty(property, style.getPropertyValue(property)),
    );
    mirror.style.position = 'absolute';
    mirror.style.visibility = 'hidden';
    mirror.style.top = '0';
    mirror.style.left = '-9999px';
    mirror.style.whiteSpace = 'pre-wrap';
    mirror.style.overflowWrap = 'break-word';

    mirror.textContent = textarea.value.slice(0, index);
    marker.textContent = textarea.value.slice(index) || '.';
    mirror.appendChild(marker);
    document.body.appendChild(mirror);

    const rect = textarea.getBoundingClientRect();
    const lineHeight =
        Number.parseFloat(style.lineHeight) ||
        Number.parseFloat(style.fontSize) * 1.4;
    const top = rect.top + marker.offsetTop - textarea.scrollTop;
    const left = rect.left + marker.offsetLeft - textarea.scrollLeft;

    mirror.remove();

    return { top, left, bottom: top + lineHeight };
}

function positionFor(textarea: HTMLTextAreaElement, index: number): Position {
    const character = characterRect(textarea, index);
    const left = Math.min(
        Math.max(character.left - 12, 8),
        window.innerWidth - menuWidth - 8,
    );

    return character.bottom + 6 + menuHeight > window.innerHeight &&
        character.top > menuHeight
        ? { left, bottom: window.innerHeight - character.top + 6 }
        : { left, top: character.bottom + 6 };
}

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

function Highlighted({ text, query }: { text: string; query: string }) {
    const index = query === '' ? -1 : text.toLowerCase().indexOf(query);

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
 * A textarea that suggests developers to mention as soon as "@" is typed, right under the "@", and turns emoji
 * shortcodes into emoji as they are typed.
 */
export function MentionTextarea({
    ref,
    value,
    onValueChange,
    onKeyDown,
    onSelect,
    onBlur,
    maxLength,
    ...props
}: Omit<ComponentProps<'textarea'>, 'value' | 'onChange'> & {
    ref?: Ref<HTMLTextAreaElement>;
    value: string;
    onValueChange: (value: string) => void;
}) {
    const id = useId();
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const listRef = useRef<HTMLUListElement>(null);
    const [mention, setMention] = useState<Mention | null>(null);
    const [position, setPosition] = useState<Position | null>(null);
    const [results, setResults] = useState<Suggestions | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);

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
        setMention(null);
        setPosition(null);
    };

    const track = (textarea: HTMLTextAreaElement) => {
        const next = mentionAt(textarea);

        if (!next) {
            close();

            return;
        }

        if (next.start !== mention?.start || next.query !== mention.query) {
            setActiveIndex(0);
        }

        setMention(next);
        setPosition(positionFor(textarea, next.start));
    };

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
        if (!mention) {
            return;
        }

        const reposition = () => {
            if (textareaRef.current) {
                setPosition(positionFor(textareaRef.current, mention.start));
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
    }, [mention]);

    useEffect(() => {
        listRef.current
            ?.querySelector('[aria-selected="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex]);

    const cached = query === null ? undefined : cache.get(query);
    const current =
        query !== null && cached ? { query, users: cached } : results;
    const suggestions =
        query !== null && current?.query === query ? current.users : [];
    const isOpen =
        mention !== null &&
        position !== null &&
        (suggestions.length > 0 || query !== '');

    const select = (user: User) => {
        const textarea = textareaRef.current;

        if (!textarea || !mention) {
            return;
        }

        const caret = mention.start + 1 + mention.query.length;
        const after = value.slice(caret);
        const handle = `@${user.username}`;
        const separator = /^\s/u.test(after) ? '' : ' ';
        let next = `${value.slice(0, mention.start)}${handle}${separator}${after}`;

        if (maxLength !== undefined && characterCount(next) > maxLength) {
            next = `${value.slice(0, mention.start)}${handle}${after}`;
        }

        if (maxLength !== undefined && characterCount(next) > maxLength) {
            close();

            return;
        }

        const nextCaret = mention.start + handle.length + 1;

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

    const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (isOpen && !event.nativeEvent.isComposing) {
            const hasModifier = event.metaKey || event.ctrlKey || event.altKey;

            if (
                (event.key === 'ArrowDown' || event.key === 'ArrowUp') &&
                suggestions.length > 0
            ) {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                setActiveIndex(
                    (index) =>
                        (index + step + suggestions.length) %
                        suggestions.length,
                );

                return;
            }

            if (
                (event.key === 'Enter' || event.key === 'Tab') &&
                !hasModifier &&
                !event.shiftKey &&
                suggestions[activeIndex]
            ) {
                event.preventDefault();
                select(suggestions[activeIndex]);

                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close();

                return;
            }
        }

        onKeyDown?.(event);
    };

    const activeId = isOpen
        ? suggestions[activeIndex] &&
          `${id}-mention-${suggestions[activeIndex].id}`
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
                aria-controls={isOpen ? `${id}-mentions` : undefined}
                aria-activedescendant={activeId}
            />

            {isOpen &&
                createPortal(
                    <div
                        className="pointer-events-auto fixed z-50 w-72 overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-[0_12px_32px_-12px_rgb(0_0_0/0.35)] motion-safe:animate-in motion-safe:fade-in-0 motion-safe:zoom-in-95"
                        style={position}
                        onMouseDown={(event) => event.preventDefault()}
                        data-mention-suggestions
                        data-test="mention-suggestions"
                    >
                        {suggestions.length > 0 ? (
                            <ul
                                ref={listRef}
                                id={`${id}-mentions`}
                                role="listbox"
                                aria-label="Mention a developer"
                                className="max-h-80 overflow-y-auto p-1.5"
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
                                        onMouseMove={() =>
                                            setActiveIndex(index)
                                        }
                                        onClick={() => select(user)}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2',
                                            {
                                                'bg-accent':
                                                    index === activeIndex,
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
                            </ul>
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
                                        fatal: no developers match '
                                        {mention?.query}'
                                    </span>
                                )}
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </>
    );
}
