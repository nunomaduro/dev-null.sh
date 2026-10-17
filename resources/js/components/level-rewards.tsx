import { router } from '@inertiajs/react';
import { Gift } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import LevelRewardController from '@/actions/App/Http/Controllers/LevelRewardController';
import { Button } from '@/components/ui/button';
import type { Achievements } from '@/types';

export function LevelRewards({
    rewards,
    canClaim,
}: {
    rewards: Achievements['level_rewards'];
    canClaim: boolean;
}) {
    const [claiming, setClaiming] = useState<number | null>(null);

    return (
        <section
            aria-label="Level rewards"
            className="border-b px-4 py-3 sm:px-6"
        >
            <h2 className="flex items-center gap-2 font-mono text-sm font-semibold">
                <Gift className="size-4" /> Level rewards
            </h2>
            <p className="mt-1 text-xs text-muted-foreground">
                Keep progressing to claim one-time Byte packs and free Shadow
                skins.
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
                {rewards.map((reward) => (
                    <div
                        key={reward.level}
                        className="flex items-center gap-2 rounded-lg border px-2.5 py-2 font-mono text-xs"
                    >
                        <span className="font-semibold">
                            Lv. {reward.level}
                        </span>
                        <span className="text-muted-foreground">
                            +{reward.bytes} Bytes
                            {reward.skin ? ` · ${reward.skin} skin` : ''}
                        </span>
                        {canClaim && reward.eligible && !reward.claimed && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={claiming !== null}
                                onClick={() => {
                                    setClaiming(reward.level);
                                    router.post(
                                        LevelRewardController.store(
                                            reward.level,
                                        ).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setClaiming(null),
                                            onSuccess: () => router.flushAll(),
                                            onError: (errors) =>
                                                toast.error(
                                                    errors.reward ??
                                                        'Could not claim reward.',
                                                ),
                                        },
                                    );
                                }}
                            >
                                Claim
                            </Button>
                        )}
                        {reward.claimed && (
                            <span className="text-muted-foreground">
                                ✓ Claimed
                            </span>
                        )}
                        {!reward.eligible && (
                            <span className="text-muted-foreground">
                                Locked
                            </span>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}
