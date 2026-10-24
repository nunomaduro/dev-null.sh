import type { Auth, SuggestedUser, User } from '@/types/auth';
import type { Commit } from '@/types/commit';
import type {
    CommittedFlash,
    DeletedFlash,
    FlashToast,
    TimeoutFlash,
} from '@/types/ui';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            toast?: FlashToast;
            timeout?: TimeoutFlash;
            achievementsUnlocked?: string[];
            committed?: CommittedFlash;
            deleted?: DeletedFlash;
            restored?: Commit;
            scheduled?: boolean;
            localSignIn?: boolean;
            signedUp?: boolean;
        };
        sharedPageProps: {
            name: string;
            repository: string;
            auth: Auth;
            hasUnmoderatedCommits: boolean;
            sidebarOpen: boolean;
            showsWhoToFollow: boolean;
            dimsLanes: boolean;
            autoPictureInPicture: boolean;
            repliesPerPage: number;
            suggestedUsers?: SuggestedUser[];
            liveFollowing?: User[];
            [key: string]: unknown;
        };
    }
}
