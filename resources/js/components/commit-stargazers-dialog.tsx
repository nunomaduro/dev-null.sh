import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { index } from '@/actions/App/Http/Controllers/CommitStarController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { UserAvatar } from '@/components/user-avatar';
import { show } from '@/routes/user';
import type { Commit, CursorPaginated, User } from '@/types';

type Stargazer = Pick<User, 'id' | 'name' | 'username' | 'avatar_url'>;

/**
 * Who starred one of the viewer's own commits, newest first, opened from the star count.
 */
export function CommitStargazersDialog({
    commit,
    count,
    children,
}: {
    commit: Pick<Commit, 'id'>;
    count: number;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [stargazers, setStargazers] = useState<Stargazer[]>([]);
    const [cursor, setCursor] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const load = async (next: string | null = null) => {
        setLoading(true);
        setFailed(false);
        try {
            const response = await fetch(
                index(commit, { query: { cursor: next } }).url,
                { headers: { Accept: 'application/json' } },
            );
            if (!response.ok) throw new Error('Unable to load stars');
            const data = (await response.json()) as CursorPaginated<Stargazer>;
            setStargazers((previous) =>
                next ? [...previous, ...data.data] : data.data,
            );
            setCursor(data.next_cursor);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    };

    // Loads again each time it opens, so stars given in the meantime show up.
    const onOpenChange = (next: boolean) => {
        setOpen(next);
        if (next) {
            setStargazers([]);
            void load();
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-sm">
                <DialogHeader className="text-left">
                    <DialogTitle className="text-base font-semibold">
                        Starred by
                    </DialogTitle>
                    <DialogDescription>
                        {count} {count === 1 ? 'person' : 'people'} starred this
                        commit.
                    </DialogDescription>
                </DialogHeader>

                <ul data-test="stargazers" className="-mx-2">
                    {stargazers.map((stargazer) => (
                        <li key={stargazer.id}>
                            <Link
                                href={show(stargazer)}
                                onClick={() => setOpen(false)}
                                className="flex items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-accent"
                            >
                                <UserAvatar
                                    user={stargazer}
                                    className="size-9"
                                />
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-medium">
                                        {stargazer.name}
                                    </span>
                                    <span className="block truncate font-mono text-[13px] text-muted-foreground">
                                        @{stargazer.username}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                    {loading &&
                        Array.from(
                            {
                                length: Math.max(
                                    1,
                                    Math.min(count - stargazers.length, 3),
                                ),
                            },
                            (_, row) => (
                                <li
                                    key={row}
                                    aria-hidden
                                    className="flex items-center gap-3 px-2 py-2 motion-safe:animate-pulse"
                                >
                                    <span className="size-9 rounded-full bg-muted" />
                                    <span className="h-3 w-2/5 rounded bg-muted" />
                                </li>
                            ),
                        )}
                </ul>

                {loading && (
                    <p role="status" className="sr-only">
                        Loading stars…
                    </p>
                )}
                {failed && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center gap-3"
                    >
                        <p className="font-mono text-[13px] text-muted-foreground">
                            fatal: could not load stars
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => void load(cursor)}
                        >
                            Try again
                        </Button>
                    </div>
                )}
                {!loading && !failed && cursor && (
                    <button
                        type="button"
                        onClick={() => void load(cursor)}
                        className="-ml-2 flex items-center self-start rounded-md px-2 py-0.5 text-[13px] font-medium text-primary transition-colors hover:bg-primary-soft"
                    >
                        Show more
                    </button>
                )}
            </DialogContent>
        </Dialog>
    );
}
