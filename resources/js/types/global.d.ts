import type { Auth, SuggestedUser } from '@/types/auth';
import type { Commit } from '@/types/commit';
import type { CommittedFlash, DeletedFlash, FlashToast } from '@/types/ui';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            toast?: FlashToast;
            committed?: CommittedFlash;
            deleted?: DeletedFlash;
            restored?: Commit;
            scheduled?: boolean;
        };
        sharedPageProps: {
            name: string;
            auth: Auth;
            hasUnmoderatedCommits: boolean;
            sidebarOpen: boolean;
            showsWhoToFollow: boolean;
            giphy: { key: string } | null;
            suggestedUsers?: SuggestedUser[];
            [key: string]: unknown;
        };
    }
}
