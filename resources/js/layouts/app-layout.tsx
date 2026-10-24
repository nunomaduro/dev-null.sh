import { Head, usePage, usePoll } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { AppAside } from '@/components/app-aside';
import {
    AppNav,
    MobileCommitButton,
    MobileHeader,
    MobileNav,
} from '@/components/app-nav';
import { ChatDock } from '@/components/chat/chat-dock';
import {
    CommitComposerDialog,
    focusVisibleComposer,
} from '@/components/commit-composer-dialog';
import { CommitImageViewer } from '@/components/commit-image-viewer';
import { CommitStarPolling } from '@/components/commit-star-polling';
import { DeletedPageDialog } from '@/components/deleted-page-dialog';
import { KeyboardShortcutsDialog } from '@/components/keyboard-shortcuts-dialog';
import { LocalSignInDialog } from '@/components/local-sign-in-dialog';
import { ProductHuntBanner } from '@/components/product-hunt-banner';
import { SearchDialog } from '@/components/search-dialog';
import { VideoDock } from '@/components/video-dock';
import { useChat, useRemoteAlerts, useUnreadMessages } from '@/hooks/use-chat';
import { useIsComposerInView } from '@/hooks/use-composer-visibility';
import { usePageDeletion } from '@/hooks/use-deleted-resources';
import { useFaviconDot } from '@/hooks/use-favicon-dot';
import { useFixedPage } from '@/hooks/use-fixed-page';
import { useKeyboardShortcuts } from '@/hooks/use-keyboard-shortcuts';
import { useNavOpen } from '@/hooks/use-nav-open';
import { useTabSlide } from '@/hooks/use-tab-slide';
import { signIn } from '@/lib/auth';
import { backgroundPollOptions } from '@/lib/background-poll';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({ children }: AppLayoutProps) {
    const {
        component,
        props: { auth },
    } = usePage();
    const { isLayoutOpen } = useNavOpen();
    const [isShowingShortcuts, setIsShowingShortcuts] = useState(false);
    const [isSearching, setIsSearching] = useState(false);
    const [isComposing, setIsComposing] = useState(false);
    const showShortcuts = useCallback(() => setIsShowingShortcuts(true), []);
    const openSearch = useCallback(() => setIsSearching(true), []);
    const isGuest = auth.user === null;
    const isMessages = component.startsWith('conversation/');
    const hasComposer = component === 'log/show' && !isGuest;
    const isComposerInView = useIsComposerInView();
    const { deletedPage, markPageDeleted } = usePageDeletion();
    const compose = useCallback(() => {
        if (isGuest) {
            signIn();

            return;
        }

        if (!focusVisibleComposer()) {
            setIsComposing(true);
        }
    }, [isGuest]);

    useKeyboardShortcuts(
        auth.user,
        auth.log,
        showShortcuts,
        openSearch,
        compose,
    );
    useTabSlide();
    const unreadMessages = useUnreadMessages();
    useFixedPage(isMessages);
    useRemoteAlerts(auth.remotes !== null ? (auth.user?.id ?? null) : null);
    useFaviconDot(auth.unreadNotificationsCount + unreadMessages > 0);

    return (
        <>
            {!isMessages && <ProductHuntBanner />}
            <div
                data-nav={isLayoutOpen ? 'expanded' : 'collapsed'}
                className="mx-auto flex min-h-svh w-full max-w-[1264px] justify-center"
            >
                {deletedPage === null && (
                    <NotificationCountPolling
                        isInbox={component === 'notification/index'}
                        onNotFound={markPageDeleted}
                    />
                )}
                <div
                    data-nav-rail
                    className="sticky top-[var(--launch-banner-height,0px)] hidden h-[calc(100svh-var(--launch-banner-height,0px))] w-20 shrink-0 md:block lg:w-(--sidebar-width)"
                >
                    <AppNav onSearch={openSearch} onCompose={compose} />
                </div>

                <main
                    data-main
                    className={cn(
                        'min-w-0 flex-1 bg-card pb-40 md:max-w-[620px] md:border-x md:pb-0 lg:max-w-[calc(876px-var(--sidebar-width))]',
                        {
                            'pb-20 md:max-w-[940px] lg:max-w-[calc(1196px-var(--sidebar-width))]':
                                isMessages,
                        },
                    )}
                >
                    <div data-nav-content>
                        <MobileHeader onSearch={openSearch} />
                        <CommitStarPolling>{children}</CommitStarPolling>
                    </div>
                </main>

                {!isMessages && (
                    <AppAside
                        onShowShortcuts={showShortcuts}
                        onSearch={openSearch}
                    />
                )}

                <MobileNav>
                    {!isMessages && (
                        <MobileCommitButton
                            isHidden={hasComposer && isComposerInView}
                            onCompose={compose}
                        />
                    )}
                </MobileNav>

                {deletedPage === null ? (
                    <CommitImageViewer />
                ) : (
                    <DeletedPageDialog page={deletedPage} />
                )}

                <VideoDock />

                {auth.user !== null && auth.remotes !== null && !isMessages && (
                    <ChatDock user={auth.user} />
                )}

                <SearchDialog
                    open={isSearching}
                    onOpenChange={setIsSearching}
                />

                <CommitComposerDialog
                    open={isComposing}
                    onOpenChange={setIsComposing}
                />

                <KeyboardShortcutsDialog
                    open={isShowingShortcuts}
                    onOpenChange={setIsShowingShortcuts}
                    canModerate={auth.isAdmin}
                />

                {isGuest && <LocalSignInDialog />}
            </div>
            <NotificationTitle />
        </>
    );
}

function NotificationTitle() {
    const {
        url,
        props: { auth },
    } = usePage();
    const { notificationTitle } = useChat();
    const unread = useUnreadMessages() + auth.unreadNotificationsCount;

    // A fresh provider takes precedence over the page's title; removing it restores that title after the alert.
    return (
        <Head
            key={JSON.stringify([url, unread, notificationTitle])}
            title={notificationTitle ?? undefined}
        />
    );
}

/**
 * The counts reload with the page's own URL, so they are also what first finds out that the page's commit is gone: the
 * page then stays as it was, instead of turning into a 404, and the polling stops until another page is visited.
 */
function NotificationCountPolling({
    isInbox,
    onNotFound,
}: {
    isInbox: boolean;
    onNotFound: () => void;
}) {
    const { start, stop } = usePoll(
        30_000,
        backgroundPollOptions({
            only: ['auth.unreadNotificationsCount', 'auth.unreadMessagesCount'],
            onHttpException: (response) => {
                if (response.status !== 404) {
                    return;
                }

                onNotFound();

                return false;
            },
        }),
        { mode: 'rest', background: 'throttle' },
    );

    useEffect(() => {
        if (!isInbox) {
            return;
        }

        const toggle = () => (document.hidden ? start() : stop());

        toggle();
        document.addEventListener('visibilitychange', toggle);

        return () => {
            document.removeEventListener('visibilitychange', toggle);
            start();
        };
    }, [isInbox, start, stop]);

    return null;
}
