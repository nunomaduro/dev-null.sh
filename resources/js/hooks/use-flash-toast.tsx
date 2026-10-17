import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { CommitUndoToast } from '@/components/commit-undo-toast';
import { dropConfetti } from '@/lib/confetti';
import { emojify } from '@/lib/emoji';
import { shortHash } from '@/lib/utils';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const {
                toast: data,
                committed,
                deleted,
                achievementsUnlocked,
            } = event.detail.flash;

            if (achievementsUnlocked?.length) {
                dropConfetti();

                for (const name of achievementsUnlocked) {
                    toast('achievement unlocked 🎉', { description: name });
                }
            }

            if (deleted) {
                const id = `deleted-${deleted.id}`;
                toast(
                    <CommitUndoToast
                        key={deleted.expires_at}
                        deleted={deleted}
                        toastId={id}
                    />,
                    {
                        id,
                        duration: Infinity,
                    },
                );
            }

            if (committed) {
                toast(
                    <span
                        className="line-clamp-3 font-mono text-[13px] [overflow-wrap:anywhere]"
                        title={committed.body}
                    >
                        [log {shortHash(committed.id)}]{' '}
                        {emojify(committed.body)}
                    </span>,
                );
            }

            if (data) {
                toast[data.type](data.message);
            }
        });
    }, []);
}
