import type { Auth } from '@/types/auth';
import type { CommittedFlash, FlashToast, OmarchyTheme } from '@/types/ui';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            toast?: FlashToast;
            committed?: CommittedFlash;
        };
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            omarchy: OmarchyTheme | null;
            [key: string]: unknown;
        };
    }
}
