import { router, usePage } from '@inertiajs/react';
import { Flame, Sparkles, Zap } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import DailyClaimController from '@/actions/App/Http/Controllers/DailyClaimController';
import { Button } from '@/components/ui/button';

export function DailyClaimBanner() {
    const { auth } = usePage().props;
    const wallet = auth.wallet;
    const [isClaiming, setIsClaiming] = useState(false);

    if (!auth.user || !wallet || wallet.has_claimed_today) {
        return null;
    }

    const handleClaim = () => {
        if (isClaiming) {
            return;
        }

        setIsClaiming(true);
        router.visit(DailyClaimController.store(), {
            preserveScroll: true,
            onFinish: () => setIsClaiming(false),
            onSuccess: () => {
                toast.success(
                    `Claimed today's Byte allocation! Your developer streak is active.`,
                );
                router.flushAll();
            },
            onError: () => {
                toast.error('Could not claim daily Bytes. Please try again.');
            },
        });
    };

    return (
        <aside
            aria-label="Daily Byte allocation"
            className="border-b bg-gradient-to-r from-primary/10 via-card to-primary/5 px-4 py-2.5 sm:px-6"
            data-test="daily-claim-banner"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                    <span className="flex size-7 items-center justify-center rounded-full bg-primary/20 text-primary">
                        <Zap className="size-4 animate-pulse" />
                    </span>
                    <div className="flex flex-col">
                        <div className="flex items-center gap-1.5 font-mono text-xs font-semibold">
                            <span>Daily Byte Epoch Active</span>
                            <span className="py-0.2 inline-flex items-center gap-0.5 rounded bg-amber-500/15 px-1.5 text-[10px] text-amber-500">
                                <Flame className="size-3" /> Streak Multiplier
                            </span>
                        </div>
                        <p className="font-mono text-[11px] text-muted-foreground">
                            Claim your daily allocation of scarce Bytes before
                            this 24h epoch expires.
                        </p>
                    </div>
                </div>

                <Button
                    type="button"
                    size="sm"
                    onClick={handleClaim}
                    disabled={isClaiming}
                    className="gap-1.5 font-mono text-xs shadow-sm hover:scale-[1.02] active:scale-[0.98]"
                >
                    <Sparkles className="size-3.5" />
                    <span>
                        {isClaiming ? 'Claiming…' : 'Claim daily Bytes'}
                    </span>
                </Button>
            </div>
        </aside>
    );
}
