import { Link, router } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { ArrowRight, Search, Sparkles } from 'lucide-react';
import type { KeyboardEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import SearchController from '@/actions/App/Http/Controllers/SearchController';
import UserSearchController from '@/actions/App/Http/Controllers/UserSearchController';
import { DialogOverlay, DialogPortal } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { UserAvatar } from '@/components/user-avatar';
import { useNow } from '@/hooks/use-now';
import {
    searchCommits,
    suggestedQueries,
    TooManySearches,
} from '@/lib/commit-search';
import { useKeyLabels } from '@/lib/keyboard';
import {
    awaitsDeveloperName,
    searchesCommits,
    searchIntent,
} from '@/lib/search-intent';
import { cn, formatRelativeTime, laneColor } from '@/lib/utils';
import { show } from '@/routes/user';
import type { Commit, User } from '@/types';

const developerDebounce = 120;

/**
 * Longer than for developers: each commit search pays for an embedding, so it waits for a pause in typing.
 */
const commitDebounce = 350;

const commitLimit = 5;

type Found<T> = { term: string; items: T[]; throttled?: boolean };

type Option =
    | { kind: 'developer'; id: string; user: User }
    | { kind: 'commit'; id: string; commit: Commit }
    | { kind: 'all'; id: string }
    | { kind: 'suggestion'; id: string; term: string };

const suggestionOptions: Option[] = suggestedQueries.map((term, index) => ({
    kind: 'suggestion',
    id: `suggestion-${index}`,
    term,
}));

/**
 * Searches developers and commits. Picking a developer for something else, like a new conversation, narrows
 * it to developers and hands the pick back instead of opening their profile.
 */
export function SearchDialog({
    open,
    onOpenChange,
    onPickDeveloper,
    excludeId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPickDeveloper?: (user: User) => void;
    excludeId?: string;
}) {
    const keys = useKeyLabels();
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const isPicking = onPickDeveloper !== undefined;

    const term = query.trim();
    const developerTerm = term.replace(/^@/u, '');
    const intent = isPicking ? 'developer' : searchIntent(term);
    const commitTerm = searchesCommits(intent) ? term : '';

    const developers = useDebouncedSearch(
        developerTerm,
        developerDebounce,
        searchDevelopers,
    );
    const commits = useDebouncedSearch(
        commitTerm,
        commitDebounce,
        (value, signal) => searchCommits(value, commitLimit, signal),
    );

    const visibleDevelopers = developers
        .resultsFor(developerTerm)
        .filter((user) => user.id !== excludeId);
    const visibleCommits = commits.resultsFor(commitTerm);
    const results = optionsFor(
        intent,
        intent === 'topic' ? visibleDevelopers.slice(0, 2) : visibleDevelopers,
        visibleCommits,
        commitTerm,
    );
    const isLoading = developers.isLoading || commits.isLoading;
    const hasSearched =
        developers.hasSearched(developerTerm) &&
        (commitTerm === '' || commits.hasSearched(commitTerm));
    const isEmpty = term === '';
    const isAwaitingDeveloperName =
        awaitsDeveloperName(term) || (isPicking && isEmpty);
    const isThrottled =
        developers.isThrottled(developerTerm) ||
        commits.isThrottled(commitTerm);
    const foundNothing =
        !isEmpty &&
        !isAwaitingDeveloperName &&
        hasSearched &&
        !isLoading &&
        results.length === 0;
    const options =
        isPicking || !(isEmpty || foundNothing) ? results : suggestionOptions;

    const type = (value: string) => {
        setQuery(value);
        setActiveIndex(0);
    };

    const close = () => {
        onOpenChange(false);
        setQuery('');
    };

    const openOption = (option: Option) => {
        if (option.kind === 'suggestion') {
            type(option.term);

            return;
        }

        close();

        if (option.kind === 'developer' && onPickDeveloper) {
            onPickDeveloper(option.user);

            return;
        }

        router.visit(hrefFor(option, term));
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (options.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActiveIndex(
                (index) => (index + step + options.length) % options.length,
            );
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            openOption(options[Math.min(activeIndex, options.length - 1)]);
        }
    };

    const activeOption = options[activeIndex];

    return (
        <DialogPrimitive.Root
            open={open}
            onOpenChange={(isOpen) => (isOpen ? onOpenChange(true) : close())}
        >
            <DialogPortal>
                <DialogOverlay />
                <DialogPrimitive.Content
                    className="fixed top-[12vh] left-1/2 z-50 w-full max-w-[calc(100%-2rem)] -translate-x-1/2 overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-[0_24px_48px_-16px_rgb(0_0_0/0.4)] duration-200 motion-safe:data-[state=closed]:animate-out motion-safe:data-[state=closed]:fade-out-0 motion-safe:data-[state=closed]:zoom-out-95 motion-safe:data-[state=open]:animate-in motion-safe:data-[state=open]:fade-in-0 motion-safe:data-[state=open]:zoom-in-95 sm:max-w-xl"
                    data-test="search"
                >
                    <DialogPrimitive.Title className="sr-only">
                        {isPicking ? 'New remote' : 'Search'}
                    </DialogPrimitive.Title>
                    <DialogPrimitive.Description className="sr-only">
                        {isPicking
                            ? 'Find a developer by name or handle to message.'
                            : 'Find developers by name or handle, or commits by what they are about.'}
                    </DialogPrimitive.Description>

                    <div className="flex items-center gap-3 border-b px-4">
                        <Search className="size-5 shrink-0 text-muted-foreground" />
                        <input
                            autoFocus
                            maxLength={100}
                            value={query}
                            onChange={(event) => type(event.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder={
                                isPicking
                                    ? 'Message a developer'
                                    : 'Search developers or commits'
                            }
                            role="combobox"
                            aria-expanded={options.length > 0}
                            aria-controls="search-results"
                            aria-activedescendant={
                                activeOption
                                    ? `search-${activeOption.id}`
                                    : undefined
                            }
                            aria-autocomplete="list"
                            autoComplete="off"
                            spellCheck={false}
                            className="h-14 min-w-0 flex-1 bg-transparent text-[17px] outline-none placeholder:text-muted-foreground focus-visible:outline-none"
                            data-test="search-input"
                        />
                        <Spinner
                            aria-hidden
                            className={cn(
                                'size-4 text-muted-foreground transition-opacity',
                                isLoading ? 'opacity-100' : 'opacity-0',
                            )}
                        />
                        <kbd className="hidden rounded border border-b-2 bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground sm:block">
                            esc
                        </kbd>
                    </div>

                    {isThrottled ? (
                        <p
                            role="status"
                            className="truncate px-5 pt-4 font-mono text-[13px] text-muted-foreground"
                        >
                            error: too many searches, give it a few seconds
                        </p>
                    ) : (
                        foundNothing && (
                            <p
                                role="status"
                                className="truncate px-5 pt-4 font-mono text-[13px] text-muted-foreground"
                                title={`fatal: nothing matches '${term}'`}
                            >
                                fatal: nothing matches '{term}'
                            </p>
                        )
                    )}

                    {options.length > 0 ? (
                        <ul
                            id="search-results"
                            role="listbox"
                            aria-label="Search results"
                            className="max-h-[min(30rem,60dvh)] overflow-y-auto overscroll-contain p-2"
                        >
                            {options.map((option, index) => (
                                <ResultOption
                                    key={option.id}
                                    option={option}
                                    term={term}
                                    heading={headingBefore(options, index)}
                                    isActive={index === activeIndex}
                                    onHover={() => setActiveIndex(index)}
                                    onOpen={close}
                                    onPick={type}
                                    onPickDeveloper={
                                        onPickDeveloper &&
                                        ((user) => {
                                            close();
                                            onPickDeveloper(user);
                                        })
                                    }
                                />
                            ))}
                        </ul>
                    ) : (
                        <div
                            role="status"
                            className="px-5 py-8 text-center text-sm text-muted-foreground"
                        >
                            {isAwaitingDeveloperName
                                ? 'Type a name or handle to find a developer'
                                : 'Searching…'}
                        </div>
                    )}

                    <div className="hidden items-center gap-4 border-t bg-muted/40 px-4 py-2 text-xs text-muted-foreground sm:flex">
                        <span className="flex items-center gap-1.5">
                            <kbd className="font-mono">↑↓</kbd> to move
                        </span>
                        <span className="flex items-center gap-1.5">
                            <kbd className="font-mono">↵</kbd>{' '}
                            {isPicking ? 'to message' : 'to open'}
                        </span>
                        {!isPicking && (
                            <span className="flex items-center gap-1.5">
                                <kbd className="font-mono">@</kbd> for
                                developers
                            </span>
                        )}
                        <span className="ml-auto flex items-center gap-1.5">
                            <kbd className="font-mono">
                                {keys.combo(keys.mod, 'K')}
                            </kbd>{' '}
                            anywhere
                        </span>
                    </div>
                </DialogPrimitive.Content>
            </DialogPortal>
        </DialogPrimitive.Root>
    );
}

/**
 * Remembers which term the results belong to, so stale results never show under a newer term, and so an empty
 * result can be told apart from one that has not arrived yet.
 */
function useDebouncedSearch<T>(
    term: string,
    debounce: number,
    search: (term: string, signal: AbortSignal) => Promise<T[]>,
) {
    const [found, setFound] = useState<Found<T>>({ term: '', items: [] });
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (term === '') {
            return;
        }

        const controller = new AbortController();
        const timeout = setTimeout(() => {
            setIsLoading(true);

            search(term, controller.signal)
                .then((items) => setFound({ term, items }))
                .catch((error: unknown) => {
                    if (error instanceof TooManySearches) {
                        setFound({ term, items: [], throttled: true });
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setIsLoading(false);
                    }
                });
        }, debounce);

        return () => {
            clearTimeout(timeout);
            controller.abort();
            setIsLoading(false);
        };
        // The search function is recreated every render; only the term should restart it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term, debounce]);

    return {
        isLoading,
        resultsFor: (current: string) =>
            current !== '' && current === found.term ? found.items : [],
        hasSearched: (current: string) => current === found.term,
        isThrottled: (current: string) =>
            current !== '' &&
            current === found.term &&
            found.throttled === true,
    };
}

function searchDevelopers(term: string, signal: AbortSignal): Promise<User[]> {
    return fetch(UserSearchController.index({ query: { q: term } }).url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        signal,
    })
        .then((response) => {
            if (response.status === 429) {
                throw new TooManySearches();
            }

            if (!response.ok) {
                throw new Error(`Developer search failed: ${response.status}`);
            }

            return response.json();
        })
        .then((data: { users: User[] }) => data.users);
}

function optionsFor(
    intent: ReturnType<typeof searchIntent>,
    developers: User[],
    commits: Commit[],
    commitTerm: string,
): Option[] {
    const developerOptions: Option[] = developers.map((user) => ({
        kind: 'developer',
        id: `developer-${user.id}`,
        user,
    }));
    const commitOptions: Option[] = commits.map((commit) => ({
        kind: 'commit',
        id: `commit-${commit.id}`,
        commit,
    }));
    const seeAll: Option[] =
        commitTerm === '' ? [] : [{ kind: 'all', id: 'all' }];

    if (intent === 'topic') {
        return [...commitOptions, ...seeAll, ...developerOptions];
    }

    return [...developerOptions, ...commitOptions, ...seeAll];
}

const headings: Record<Option['kind'], ReactNode> = {
    developer: 'Developers',
    commit: 'Most relevant commits',
    all: null,
    suggestion: (
        <span className="flex items-center gap-1.5">
            <Sparkles aria-hidden className="size-3.5 text-primary" />
            Try searching for…
        </span>
    ),
};

function headingBefore(options: Option[], index: number): ReactNode {
    const kind = options[index].kind;

    if (options[index - 1]?.kind === kind) {
        return null;
    }

    return headings[kind];
}

function hrefFor(
    option: Exclude<Option, { kind: 'suggestion' }>,
    term: string,
) {
    if (option.kind === 'developer') {
        return show(option.user);
    }

    if (option.kind === 'commit') {
        return CommitController.show({
            user: option.commit.user,
            commit: option.commit,
        });
    }

    return SearchController.index({ query: { q: term } });
}

function ResultOption({
    option,
    term,
    heading,
    isActive,
    onHover,
    onOpen,
    onPick,
    onPickDeveloper,
}: {
    option: Option;
    term: string;
    heading: ReactNode;
    isActive: boolean;
    onHover: () => void;
    onOpen: () => void;
    onPick: (term: string) => void;
    onPickDeveloper?: (user: User) => void;
}) {
    const className = cn(
        'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left',
        { 'bg-accent': isActive },
    );
    const content = (
        <>
            <OptionContent option={option} />
            <kbd
                aria-hidden
                className={cn(
                    'shrink-0 font-mono text-xs text-muted-foreground opacity-0',
                    { 'opacity-100': isActive },
                )}
            >
                ↵
            </kbd>
        </>
    );

    return (
        <>
            {heading && (
                <li
                    role="presentation"
                    className="px-3 pt-2 pb-1 font-mono text-xs text-muted-foreground first:pt-1"
                >
                    {heading}
                </li>
            )}
            <li
                id={`search-${option.id}`}
                role="option"
                aria-selected={isActive}
            >
                {option.kind === 'suggestion' ||
                (option.kind === 'developer' && onPickDeveloper) ? (
                    <button
                        type="button"
                        tabIndex={-1}
                        onMouseMove={onHover}
                        onClick={() =>
                            option.kind === 'developer'
                                ? onPickDeveloper?.(option.user)
                                : option.kind === 'suggestion' &&
                                  onPick(option.term)
                        }
                        className={className}
                    >
                        {content}
                    </button>
                ) : (
                    <Link
                        href={hrefFor(option, term)}
                        tabIndex={-1}
                        onMouseMove={onHover}
                        onClick={onOpen}
                        className={className}
                    >
                        {content}
                    </Link>
                )}
            </li>
        </>
    );
}

function OptionContent({ option }: { option: Option }): ReactNode {
    if (option.kind === 'developer') {
        return <DeveloperResult user={option.user} />;
    }

    if (option.kind === 'commit') {
        return <CommitResult commit={option.commit} />;
    }

    if (option.kind === 'suggestion') {
        return (
            <span className="flex min-w-0 flex-1 items-center justify-between gap-3 text-sm">
                <span className="truncate" title={option.term}>
                    {option.term}
                </span>
                <ArrowRight
                    aria-hidden
                    className="size-4 shrink-0 text-muted-foreground"
                />
            </span>
        );
    }

    return (
        <span className="flex min-w-0 flex-1 items-center gap-2 text-sm text-primary">
            See all matching commits
            <ArrowRight aria-hidden className="size-4" />
        </span>
    );
}

function DeveloperResult({ user }: { user: User }) {
    return (
        <>
            <UserAvatar user={user} className="size-9 shrink-0" />
            <span className="min-w-0 flex-1">
                <span className="flex items-baseline gap-1.5">
                    <span className="truncate font-semibold">{user.name}</span>
                    <Handle user={user} />
                </span>
                {user.bio && (
                    <span className="block truncate text-sm text-muted-foreground">
                        {user.bio}
                    </span>
                )}
            </span>
        </>
    );
}

function CommitResult({ commit }: { commit: Commit }) {
    const now = useNow();

    return (
        <>
            <UserAvatar
                user={commit.user}
                className="size-9 shrink-0 self-start"
            />
            <span className="min-w-0 flex-1">
                <span className="flex items-baseline gap-1.5 text-sm">
                    <Handle user={commit.user} />
                    <span aria-hidden className="text-muted-foreground">
                        ·
                    </span>
                    <time
                        dateTime={commit.created_at}
                        className="shrink-0 font-mono text-[13px] text-muted-foreground"
                    >
                        {formatRelativeTime(commit.created_at, now)}
                    </time>
                </span>
                <span
                    className="line-clamp-2 text-sm text-pretty"
                    title={commit.body}
                >
                    {commit.body}
                </span>
            </span>
        </>
    );
}

function Handle({ user }: { user: User }) {
    return (
        <span className="flex min-w-0 shrink items-center gap-1 font-mono text-[13px] text-muted-foreground">
            <span
                aria-hidden
                className="size-1.5 shrink-0 rounded-full"
                style={{ background: laneColor(user.lane) }}
            />
            <span className="truncate">@{user.username}</span>
        </span>
    );
}
