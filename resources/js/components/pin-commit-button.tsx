import { router } from '@inertiajs/react';
import { Pin, PinOff } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import CommitPinController from '@/actions/App/Http/Controllers/CommitPinController';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { toastWhenThrottled } from '@/lib/throttle';
import type { Commit } from '@/types';

export function PinCommitButton({
    commit,
    isPinned,
}: {
    commit: Commit;
    isPinned: boolean;
}) {
    const [isSaving, setIsSaving] = useState(false);
    const Icon = isPinned ? PinOff : Pin;
    const label = isPinned ? 'Unpin from profile' : 'Pin to profile';

    const togglePin = () => {
        router.visit(
            isPinned
                ? CommitPinController.destroy(commit)
                : CommitPinController.update(commit),
            {
                only: ['commits', 'pinnedCommit'],
                reset: ['commits'],
                onStart: () => setIsSaving(true),
                onFinish: () => setIsSaving(false),
                onSuccess: () =>
                    toast.success(
                        isPinned
                            ? 'Commit unpinned.'
                            : 'Commit pinned to your profile.',
                    ),
                onError: () =>
                    toast.error('Could not update your pinned commit.'),
                onHttpException: (response) => !toastWhenThrottled(response),
            },
        );
    };

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={togglePin}
                    disabled={isSaving}
                    aria-label={label}
                    aria-pressed={isPinned}
                    aria-busy={isSaving}
                    className="flex items-center gap-1.5 rounded-md px-2 py-0.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:cursor-wait disabled:opacity-50"
                >
                    <Icon aria-hidden className="size-3.5" />
                    {isSaving ? 'Saving…' : isPinned ? 'Unpin' : 'Pin'}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
