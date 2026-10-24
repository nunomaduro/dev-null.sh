import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import LogController from '@/actions/App/Http/Controllers/LogController';
import { signIn } from '@/lib/auth';
import { hasModKey } from '@/lib/keyboard';
import { show } from '@/routes/user';
import { edit } from '@/routes/user-profile';
import type { Log, User } from '@/types';

export function isTyping(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

function moveCommitFocus(step: 1 | -1): void {
    const commits = [
        ...document.querySelectorAll<HTMLElement>('[data-commit]'),
    ];

    if (commits.length === 0) {
        return;
    }

    const current = commits.findIndex((commit) =>
        commit.contains(document.activeElement),
    );
    const next =
        current === -1
            ? step === 1
                ? 0
                : commits.length - 1
            : Math.min(Math.max(current + step, 0), commits.length - 1);

    focusCommit(commits[next]);
}

export function focusCommit(commit: HTMLElement): void {
    commit.focus({ preventScroll: true });
    commit.scrollIntoView({ block: 'nearest' });
}

/**
 * A deleted commit's actions are inert, which stops pointers and focus but not a scripted click.
 */
function focusedCommitAction(key: string): HTMLButtonElement | null {
    const action = (
        document.activeElement?.closest('[data-commit]') ??
        document.querySelector('[data-focused-commit]')
    )?.querySelector<HTMLButtonElement>(
        `button[aria-keyshortcuts="${CSS.escape(key)}"]`,
    );

    return action && !action.closest('[inert]') ? action : null;
}

function openFocusedCommit(): boolean {
    const commit = document.activeElement;

    if (
        !(commit instanceof HTMLElement) ||
        !commit.matches('[data-commit]') ||
        !commit.dataset.permalink
    ) {
        return false;
    }

    router.visit(commit.dataset.permalink);

    return true;
}

/**
 * Guests have no log, profile, or settings to go to, so those shortcuts take them to sign in.
 */
export function useKeyboardShortcuts(
    user: User | null,
    log: Log | null,
    onShowHelp: () => void,
    onSearch: () => void,
    onCompose: () => void,
): void {
    useEffect(() => {
        let awaitingGoTo = false;
        let goToTimeout: ReturnType<typeof setTimeout> | undefined;

        const goTo: Record<string, () => void> =
            user === null || log === null
                ? { l: signIn, e: signIn, p: signIn, s: signIn }
                : {
                      l: () => router.visit(LogController.show(log)),
                      e: () => router.visit(LogController.show('trending')),
                      p: () => router.visit(show(user)),
                      s: () => router.visit(edit()),
                  };

        const onKeyDown = (event: KeyboardEvent) => {
            if (hasModKey(event) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                onSearch();

                return;
            }

            if (
                event.defaultPrevented ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                isTyping(event.target) ||
                document.querySelector(
                    '[role="dialog"][data-state="open"], [role="dialog"][aria-modal="true"]',
                )
            ) {
                return;
            }

            if (awaitingGoTo) {
                awaitingGoTo = false;
                clearTimeout(goToTimeout);

                if (goTo[event.key]) {
                    event.preventDefault();
                    goTo[event.key]();
                }

                return;
            }

            if (event.key === 'Enter') {
                if (openFocusedCommit()) {
                    event.preventDefault();
                }

                return;
            }

            const commitAction = focusedCommitAction(event.key);

            if (commitAction) {
                event.preventDefault();
                commitAction.click();

                return;
            }

            const actions: Record<string, () => void> = {
                j: () => moveCommitFocus(1),
                k: () => moveCommitFocus(-1),
                c: onCompose,
                '/': onSearch,
                '?': onShowHelp,
                g: () => {
                    awaitingGoTo = true;
                    goToTimeout = setTimeout(() => {
                        awaitingGoTo = false;
                    }, 1000);
                },
            };

            if (actions[event.key]) {
                event.preventDefault();
                actions[event.key]();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
            clearTimeout(goToTimeout);
        };
    }, [user, log, onShowHelp, onSearch, onCompose]);
}
