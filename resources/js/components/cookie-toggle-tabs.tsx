import { router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ToggleLeft, ToggleRight } from 'lucide-react';
import { useState } from 'react';
import { SegmentedControl } from '@/components/segmented-control';
import { setCookie } from '@/hooks/use-appearance';
import { withViewTransition } from '@/lib/utils';

type ToggleProp = 'dimsLanes' | 'autoPictureInPicture';

/**
 * An On and Off choice kept in a cookie, which the server reads back into the shared prop of the same setting.
 */
export default function CookieToggleTabs({
    prop,
    cookie,
    label,
    name,
}: {
    prop: ToggleProp;
    cookie: string;
    label: string;
    name: string;
}) {
    const isOn = usePage().props[prop];
    const [selected, setSelected] = useState(isOn);

    const tabs: { value: boolean; icon: LucideIcon; label: string }[] = [
        { value: true, icon: ToggleRight, label: 'On' },
        { value: false, icon: ToggleLeft, label: 'Off' },
    ];

    const select = (value: boolean): void => {
        if (value === selected) {
            return;
        }

        void withViewTransition(() => setSelected(value)).then(() => {
            setCookie(cookie, String(value));
            router.flushAll();
            router.reload({ only: [prop] });
        });
    };

    return (
        <SegmentedControl
            label={label}
            name={name}
            options={tabs}
            value={selected}
            onChange={select}
        />
    );
}
