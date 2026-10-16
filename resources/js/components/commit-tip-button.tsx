import { usePage } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import { useState } from 'react';
import { CommitTipDialog } from '@/components/commit-tip-dialog';
import { signIn } from '@/lib/auth';
import { cn } from '@/lib/utils';
import type { Commit } from '@/types';

export function CommitTipButton({
    commit,
    className,
}: {
    commit: Commit;
    className?: string;
}) {
    const { auth } = usePage().props;
    const [isOpen, setIsOpen] = useState(false);
    const isOwn = commit.user_id === auth.user?.id;
    const tipsCount = commit.tips_count ?? 0;
    const tipsTotalBytes = commit.tips_total_bytes ?? 0;

    if (commit.status !== 'approved') {
        return null;
    }

    const handleClick = () => {
        if (auth.user === null) {
            signIn();

            return;
        }

        if (isOwn) {
            return;
        }

        setIsOpen(true);
    };

    const label =
        tipsCount > 0
            ? `${tipsTotalBytes} Bytes gifted (${tipsCount} ${tipsCount === 1 ? 'tip' : 'tips'})`
            : 'Tip Bytes for this commit';

    return (
        <>
            <button
                type="button"
                onClick={handleClick}
                disabled={isOwn && tipsCount === 0}
                aria-label={label}
                title={
                    isOwn
                        ? tipsCount > 0
                            ? label
                            : "You can't tip your own commit"
                        : label
                }
                className={cn(
                    'group/tip inline-flex items-center gap-1 rounded-md px-1.5 py-1 font-mono text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-muted-foreground',
                    tipsCount > 0 &&
                        'text-amber-500/90 hover:text-amber-500 dark:text-amber-400/90',
                    className,
                )}
                data-test="tip-commit-button"
            >
                <Coins
                    aria-hidden
                    className="size-4 transition-transform group-hover/tip:scale-110 active:scale-95"
                />
                <span data-test="tip-bytes" className="min-w-[1ch] text-left">
                    {tipsTotalBytes > 0 ? `${tipsTotalBytes}B` : ''}
                </span>
            </button>

            {!isOwn && (
                <CommitTipDialog
                    commit={commit}
                    open={isOpen}
                    onOpenChange={setIsOpen}
                />
            )}
        </>
    );
}
