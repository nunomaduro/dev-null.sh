import { usePage, usePoll } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { AppAside } from '@/components/app-aside';
import { AppNav, MobileHeader, MobileNav } from '@/components/app-nav';
import { ChatDock } from '@/components/chat/chat-dock';
import {
    CommitComposerDialog,
    focusVisibleComposer,
} from '@/components/commit-composer-dialog';
import { CommitImageViewer } from '@/components/commit-image-viewer';
import { CommitStarPolling } from '@/components/commit-star-polling';
import { KeyboardShortcutsDialog } from '@/components/keyboard-shortcuts-dialog';
import { SearchDialog } from '@/components/search-dialog';
import { useKeyboardShortcuts } from '@/hooks/use-keyboard-shortcuts';
import { useTabSlide } from '@/hooks/use-tab-slide';
import { signIn } from '@/lib/auth';
import { cn } from '@/lib/utils';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({ children }: AppLayoutProps) {
    const {
        component,
        props: { auth },
    } = usePage();
    const [isShowingShortcuts, setIsShowingShortcuts] = useState(false);
    const [isSearching, setIsSearching] = useState(false);
    const [isComposing, setIsComposing] = useState(false);
    const showShortcuts = useCallback(() => setIsShowingShortcuts(true), []);
    const openSearch = useCallback(() => setIsSearching(true), []);
    const isGuest = auth.user === null;
    const isMessages = component.startsWith('conversation/');
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

    return (
        <div className="mx-auto flex min-h-svh w-full max-w-[1264px] justify-center">
            {component !== 'notification/index' && <NotificationCountPolling />}
            <AppNav onSearch={openSearch} onCompose={compose} />

            <main
                className={cn(
                    'min-w-0 flex-1 bg-card pb-20 md:max-w-[620px] md:border-x md:pb-0',
                    { 'md:max-w-[940px]': isMessages },
                )}
            >
                <MobileHeader onSearch={openSearch} />
                <CommitStarPolling>{children}</CommitStarPolling>
            </main>

            {!isMessages && (
                <AppAside
                    onShowShortcuts={showShortcuts}
                    onSearch={openSearch}
                />
            )}

            <MobileNav />

            <CommitImageViewer />

            {auth.user !== null && auth.remotes !== null && !isMessages && (
                <ChatDock user={auth.user} />
            )}

            <SearchDialog open={isSearching} onOpenChange={setIsSearching} />

            <CommitComposerDialog
                open={isComposing}
                onOpenChange={setIsComposing}
            />

            <KeyboardShortcutsDialog
                open={isShowingShortcuts}
                onOpenChange={setIsShowingShortcuts}
                canModerate={auth.isAdmin}
            />
        </div>
    );
}

function NotificationCountPolling() {
    usePoll(
        30_000,
        { only: ['auth.unreadNotificationsCount', 'auth.unreadMessagesCount'] },
        { mode: 'rest', background: 'pause' },
    );

    return null;
}
