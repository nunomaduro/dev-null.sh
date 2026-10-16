import type { PropsWithChildren } from 'react';
import { PageTabs } from '@/components/page-tabs';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { edit as editAppearance } from '@/routes/appearance';
import { index as devices } from '@/routes/device';
import { edit as editSafety } from '@/routes/safety';
import { show as showTerminal } from '@/routes/terminal';
import { edit } from '@/routes/user-profile';

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <>
            <div className="sticky top-0 z-10 bg-card [view-transition-class:tab-header] [view-transition-name:settings-header]">
                <h1 className="flex h-14 items-center border-b px-4 font-semibold sm:px-6">
                    Settings
                </h1>
                <PageTabs
                    label="Settings"
                    tabs={[
                        {
                            title: 'Profile',
                            href: edit(),
                            isActive: isCurrentUrl(edit()),
                        },
                        {
                            title: 'Appearance',
                            href: editAppearance(),
                            isActive: isCurrentUrl(editAppearance()),
                        },
                        {
                            title: 'Terminal',
                            href: showTerminal(),
                            isActive: isCurrentUrl(showTerminal()),
                        },
                        {
                            title: 'Safety',
                            href: editSafety(),
                            isActive: isCurrentUrl(editSafety()),
                        },
                        {
                            title: 'Devices',
                            href: devices(),
                            isActive: isCurrentUrl(devices()),
                        },
                    ]}
                />
            </div>

            <div className="divide-y [view-transition-class:tab-content] [view-transition-name:settings-content]">
                {children}
            </div>
        </>
    );
}
