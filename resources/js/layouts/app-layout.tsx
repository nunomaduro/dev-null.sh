import { usePage, usePoll } from '@inertiajs/react';
import { lazy, Suspense, useCallback, useState } from 'react';
import { AppAside } from '@/components/app-aside';
import { AppNav, MobileHeader, MobileNav } from '@/components/app-nav';
import {
    CommitComposerDialog,
    focusVisibleComposer,
} from '@/components/commit-composer-dialog';
import { CommitStarPolling } from '@/components/commit-star-polling';
import { KeyboardShortcutsDialog } from '@/components/keyboard-shortcuts-dialog';
import { SearchDialog } from '@/components/search-dialog';
import { useKeyboardShortcuts } from '@/hooks/use-keyboard-shortcuts';
import { useTabSlide } from '@/hooks/use-tab-slide';
import { signIn } from '@/lib/auth';
import type { CommitPhoto } from '@/lib/commit-photo';
import type { AppLayoutProps } from '@/types';

// The lightbox is only needed once a photo opens, so it loads then instead of on every page.
const CommitImageViewer = lazy(async () => ({
    default: (await import('@/components/commit-image-viewer'))
        .CommitImageViewer,
}));

export default function AppLayout({ children }: AppLayoutProps) {
    const {
        component,
        props: { auth, photo },
    } = usePage<{ photo?: CommitPhoto }>();
    const [isShowingShortcuts, setIsShowingShortcuts] = useState(false);
    const [isSearching, setIsSearching] = useState(false);
    const [isComposing, setIsComposing] = useState(false);
    // Stays mounted after the photo closes, so the lightbox can animate out.
    const [hasShownPhoto, setHasShownPhoto] = useState(false);

    if (photo !== undefined && !hasShownPhoto) {
        setHasShownPhoto(true);
    }
    const showShortcuts = useCallback(() => setIsShowingShortcuts(true), []);
    const openSearch = useCallback(() => setIsSearching(true), []);
    const isGuest = auth.user === null;
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

            <main className="min-w-0 flex-1 bg-card pb-20 md:max-w-[620px] md:border-x md:pb-0">
                <MobileHeader onSearch={openSearch} />
                <CommitStarPolling>{children}</CommitStarPolling>
            </main>

            <AppAside onShowShortcuts={showShortcuts} onSearch={openSearch} />

            <MobileNav />

            {hasShownPhoto && (
                <Suspense>
                    <CommitImageViewer />
                </Suspense>
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
        { only: ['auth.unreadNotificationsCount'] },
        { mode: 'rest', background: 'pause' },
    );

    return null;
}
