import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType } from 'react';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { TimeoutDialog } from '@/components/timeout-dialog';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { trackAnalyticsEvents } from '@/lib/analytics';
import { trackCommitPhotoPage } from '@/lib/commit-photo';
import { initializeCommitState } from '@/lib/commit-state';
import { trackCurrentPage } from '@/lib/current-page';
import { trackFocusedCommitScroll } from '@/lib/focused-commit-scroll';
import { trackFollowRequests } from '@/lib/follow-requests';
import { pageTitle } from '@/lib/page-title';
import { registerServiceWorker } from '@/lib/service-worker';
import { trackVisualViewport } from '@/lib/visual-viewport';
import '../css/app.css';

trackCurrentPage();
trackCommitPhotoPage();
trackFocusedCommitScroll();
trackVisualViewport();
trackAnalyticsEvents();

void createInertiaApp({
    title: pageTitle,
    resolve: (name) =>
        resolvePageComponent<ComponentType>(
            `./pages/${name}.tsx`,
            import.meta.glob<ComponentType>('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        trackFollowRequests(props.initialPage);
        registerServiceWorker(props.initialPage.version);

        const root = createRoot(el);

        root.render(
            <StrictMode>
                <TooltipProvider delayDuration={150}>
                    <App {...props} />
                    <Toaster />
                    <TimeoutDialog />
                </TooltipProvider>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

initializeCommitState();
