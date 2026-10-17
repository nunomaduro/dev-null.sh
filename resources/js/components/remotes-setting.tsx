import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { update } from '@/actions/App/Http/Controllers/UserRemotesController';
import { cn } from '@/lib/utils';

/**
 * Remotes are opt-in: nobody can message the user, and the Remotes page stays hidden, until they turn this on.
 */
export function RemotesSetting() {
    const { auth } = usePage().props;
    const enabled = auth.remotes !== null;
    const [processing, setProcessing] = useState(false);

    const toggle = () => {
        if (processing) {
            return;
        }

        router.put(
            update.url(),
            { enabled: !enabled },
            {
                preserveScroll: true,
                only: ['auth'],
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: () =>
                    toast.error('Could not save remotes. Please try again.'),
                onSuccess: () => router.flushAll(),
            },
        );
    };

    return (
        <section className="px-4 py-6 sm:px-6">
            <div className="flex items-start justify-between gap-6">
                <div className="min-w-0 space-y-2">
                    <h2 id="remotes-label" className="text-base font-semibold">
                        Remotes
                    </h2>
                    <p
                        id="remotes-description"
                        className="text-sm text-muted-foreground"
                    >
                        One-to-one messages with other developers. People you
                        follow can push to you straight away; everyone else
                        lands in Fetched until you merge them. You can change
                        who can push, and whether you share pulls, from Remotes.
                    </p>
                    <p className="text-sm text-muted-foreground">
                        When off, nobody can message you and Remotes is hidden.
                        Your conversations are kept for when you turn it back
                        on.
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    aria-checked={enabled}
                    aria-labelledby="remotes-label"
                    aria-describedby="remotes-description"
                    aria-disabled={processing}
                    onClick={toggle}
                    className={cn(
                        'mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full border border-transparent p-0.5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                        enabled ? 'bg-primary' : 'bg-muted-foreground/30',
                    )}
                    data-test="remotes-setting"
                >
                    <span
                        className={cn(
                            'size-4.5 rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none',
                            enabled && 'translate-x-5',
                        )}
                    />
                </button>
            </div>
        </section>
    );
}
