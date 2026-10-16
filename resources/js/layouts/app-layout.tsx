import { usePage, usePoll } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { AppAside } from '@/components/app-aside';
import { AppNav, MobileHeader, MobileNav } from '@/components/app-nav';
import { CommitImageViewer } from '@/components/commit-image-viewer';
import { CommitStarPolling } from '@/components/commit-star-polling';
import { KeyboardShortcutsDialog } from '@/components/keyboard-shortcuts-dialog';
import { LiveStreamBanner } from '@/components/live-stream';
import { UserSearchDialog } from '@/components/user-search-dialog';
import { useKeyboardShortcuts } from '@/hooks/use-keyboard-shortcuts';
import { useTabSlide } from '@/hooks/use-tab-slide';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({ children }: AppLayoutProps) {
    const {
        component,
        props: { auth },
    } = usePage();
    const [isShowingShortcuts, setIsShowingShortcuts] = useState(false);
    const [isSearching, setIsSearching] = useState(false);
    const showShortcuts = useCallback(() => setIsShowingShortcuts(true), []);
    const openSearch = useCallback(() => setIsSearching(true), []);

    useKeyboardShortcuts(auth.user, auth.log, showShortcuts, openSearch);
    useTabSlide();

    return (
        <div className="mx-auto flex min-h-svh w-full max-w-[1264px] justify-center">
            {component !== 'notification/index' && <NotificationCountPolling />}
            <AppNav onSearch={openSearch} />

            <main className="min-w-0 flex-1 bg-card pb-20 md:max-w-[620px] md:border-x md:pb-0">
                <LiveStreamBanner />
                <MobileHeader onSearch={openSearch} />
                <CommitStarPolling>{children}</CommitStarPolling>
            </main>

            <AppAside onShowShortcuts={showShortcuts} onSearch={openSearch} />

            <MobileNav />

            <CommitImageViewer />

            <UserSearchDialog
                open={isSearching}
                onOpenChange={setIsSearching}
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
