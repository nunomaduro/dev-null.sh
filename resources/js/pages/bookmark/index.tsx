import { Head, Link, router } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { useRef } from 'react';
import LogController from '@/actions/App/Http/Controllers/LogController';
import { BookmarkRemovalContext } from '@/components/bookmark-button';
import { CommitFeed, EmptyFeed } from '@/components/commit-feed';
import { focusCommit } from '@/hooks/use-keyboard-shortcuts';
import AppLayout from '@/layouts/app-layout';
import type { Commit, CursorPaginated } from '@/types';

export default function Index({
    commits,
}: {
    commits: CursorPaginated<Commit>;
}) {
    const headingRef = useRef<HTMLHeadingElement>(null);
    const remove = (id: string) => {
        const index = commits.data.findIndex((commit) => commit.id === id);

        if (index === -1) {
            return;
        }

        const next = commits.data[index + 1] ?? commits.data[index - 1];
        const current = document.querySelector(`[data-commit="${id}"]`);

        if (current?.contains(document.activeElement)) {
            const target = next
                ? document.querySelector<HTMLElement>(
                      `[data-commit="${next.id}"]`,
                  )
                : headingRef.current;

            if (target) {
                focusCommit(target);
            }
        }

        if (commits.data.length === 1 && commits.next_cursor !== null) {
            router.reload({ only: ['commits'], reset: ['commits'] });
        } else {
            router.replaceProp('commits.data', (data: Commit[]) =>
                data.filter((commit) => commit.id !== id),
            );
        }
    };

    return (
        <>
            <Head title="Saved" />

            <header
                data-impression-occluder="top"
                className="sticky top-0 z-10 flex h-14 items-center justify-between gap-3 border-b bg-card px-4 sm:px-6"
            >
                <h1 ref={headingRef} tabIndex={-1} className="font-semibold">
                    Saved
                </h1>
                <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                    <LockKeyhole aria-hidden className="size-3.5" />
                    Only you
                </span>
            </header>

            <p className="border-b px-4 py-3 text-sm text-muted-foreground sm:px-6">
                Commits you want to come back to. Most recently saved first.
            </p>

            <BookmarkRemovalContext value={remove}>
                <CommitFeed
                    commits={commits}
                    end="You've reached the end of your saved commits."
                    empty={
                        <EmptyFeed command="fatal: no saved commits yet">
                            Save a commit to find it here. Your saved commits
                            are private.
                            <Link
                                href={LogController.show('trending')}
                                className="mt-3 block text-primary hover:underline"
                            >
                                Explore Trending
                            </Link>
                        </EmptyFeed>
                    }
                />
            </BookmarkRemovalContext>
        </>
    );
}

Index.layout = AppLayout;
