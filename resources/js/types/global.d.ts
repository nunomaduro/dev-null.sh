import type { Auth, SuggestedUser } from '@/types/auth';
import type { CommittedFlash, FlashToast } from '@/types/ui';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            toast?: FlashToast;
            committed?: CommittedFlash;
        };
        sharedPageProps: {
            name: string;
            auth: Auth;
            hasUnmoderatedCommits: boolean;
            sidebarOpen: boolean;
            showsWhoToFollow: boolean;
            suggestedUsers?: SuggestedUser[];
            onlineDevelopersCount: number;
            [key: string]: unknown;
        };
    }
}
