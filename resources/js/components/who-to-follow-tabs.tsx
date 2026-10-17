import type { LucideIcon } from 'lucide-react';
import { Eye, EyeOff } from 'lucide-react';
import { useRef, useState } from 'react';
import { SegmentedControl } from '@/components/segmented-control';
import { useWhoToFollow } from '@/hooks/use-who-to-follow';
import { withViewTransition } from '@/lib/utils';

export default function WhoToFollowTabs() {
    const { showsWhoToFollow, setShowsWhoToFollow } = useWhoToFollow();
    const [selected, setSelected] = useState(showsWhoToFollow);
    const requestedSelection = useRef(showsWhoToFollow);

    const tabs: { value: boolean; icon: LucideIcon; label: string }[] = [
        { value: true, icon: Eye, label: 'Show' },
        { value: false, icon: EyeOff, label: 'Hide' },
    ];

    const select = (value: boolean): void => {
        if (value === requestedSelection.current) {
            return;
        }

        requestedSelection.current = value;
        void withViewTransition(() => setSelected(value)).then(() =>
            setShowsWhoToFollow(value),
        );
    };

    return (
        <SegmentedControl
            label="Who to follow"
            name="who-to-follow"
            options={tabs}
            value={selected}
            onChange={select}
        />
    );
}
