import { router, usePage } from '@inertiajs/react';
import { Coins, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import CommitTipController from '@/actions/App/Http/Controllers/CommitTipController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn, laneColor, shortHash } from '@/lib/utils';
import type { Commit, CommitGiftInfo, CommitGiftType } from '@/types';

export const COMMIT_GIFTS: CommitGiftInfo[] = [
    {
        type: 'coffee',
        label: 'Coffee',
        icon: '☕',
        cost: 10,
        description: 'Warm brew for a clean commit',
    },
    {
        type: 'beer',
        label: 'Cold Brew',
        icon: '🍺',
        cost: 25,
        description: 'Caffeine kick for late-night refactor',
    },
    {
        type: 'overclock',
        label: 'Overclock',
        icon: '⚡',
        cost: 50,
        description: 'High-frequency performance boost',
    },
    {
        type: 'rocket',
        label: 'Ship to Prod',
        icon: '🚀',
        cost: 100,
        description: 'Deploy straight to production with confidence',
    },
    {
        type: 'root',
        label: 'Sudo Grant',
        icon: '👑',
        cost: 250,
        description: 'Ultimate privilege for an exceptional commit',
    },
    {
        type: 'signal',
        label: 'Signal Beacon',
        icon: '⌁',
        ascii: '  /\\  \n <  * >\n  \\/  ',
        cost: 75,
        description: 'A little signal across the terminal void',
    },
    {
        type: 'phoenix',
        label: 'Null Phoenix',
        icon: '✹',
        ascii: ' \\ | /\n-- * --\n / | \\',
        cost: 175,
        description: 'Rise from a failed build with style',
    },
    {
        type: 'eclipse',
        label: 'Kernel Eclipse',
        icon: '◉',
        ascii: ' .---. \n(  @  )\n `---` ',
        cost: 500,
        description: 'A rare kernel orbit for extraordinary work',
    },
];

export function CommitTipDialog({
    commit,
    open,
    onOpenChange,
}: {
    commit: Commit;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { auth } = usePage().props;
    const balance = auth.wallet?.balance ?? 0;
    const [selectedGift, setSelectedGift] = useState<CommitGiftType>('coffee');
    const [message, setMessage] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    const gift =
        COMMIT_GIFTS.find((g) => g.type === selectedGift) ?? COMMIT_GIFTS[0];
    const canAfford = balance >= gift.cost;
    const hash = shortHash(commit.id);
    const authorLane = laneColor(commit.user.lane);

    const handleSendTip = () => {
        if (!canAfford || isSubmitting) {
            return;
        }

        setIsSubmitting(true);
        router.visit(CommitTipController.store(commit), {
            data: {
                gift: selectedGift,
                message: message.trim() || undefined,
            },
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
            onSuccess: () => {
                toast.success(
                    `Sent ${gift.icon} ${gift.label} (${gift.cost} Bytes) to @${commit.user.username}!`,
                );
                onOpenChange(false);
                setMessage('');
                router.flushAll();
            },
            onError: (errors) => {
                const errorMessage =
                    errors.gift ||
                    errors.message ||
                    Object.values(errors)[0] ||
                    'Could not send tip.';
                toast.error(errorMessage);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md gap-4 sm:max-w-lg">
                <DialogHeader className="gap-1.5">
                    <DialogTitle className="flex items-center gap-2 text-left font-mono">
                        <Sparkles className="size-4 text-primary" />
                        <span>Gift Bytes for Commit</span>
                        <span className="font-normal text-muted-foreground">
                            {hash}
                        </span>
                    </DialogTitle>
                    <DialogDescription className="text-left text-xs">
                        Tip{' '}
                        <span
                            className="font-mono font-medium"
                            style={{ color: authorLane }}
                        >
                            @{commit.user.username}
                        </span>{' '}
                        with scarce Bytes from your developer wallet.
                    </DialogDescription>
                </DialogHeader>

                {/* Wallet Balance Banner */}
                <div className="flex items-center justify-between rounded-md border bg-muted/40 px-3 py-2 font-mono text-xs">
                    <span className="flex items-center gap-1.5 text-muted-foreground">
                        <Coins className="size-3.5 text-primary" />
                        Your Wallet Balance:
                    </span>
                    <span className="font-semibold text-foreground">
                        {balance} Bytes
                    </span>
                </div>

                {/* Gift Select Grid */}
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {COMMIT_GIFTS.map((g) => {
                        const isSelected = selectedGift === g.type;
                        const affordable = balance >= g.cost;

                        return (
                            <button
                                key={g.type}
                                type="button"
                                onClick={() => setSelectedGift(g.type)}
                                className={cn(
                                    'flex cursor-pointer flex-col gap-1 rounded-md border p-2.5 text-left transition-all',
                                    isSelected
                                        ? 'border-primary bg-primary/10 shadow-sm'
                                        : 'border-border/60 hover:border-border hover:bg-muted/30',
                                    !affordable && 'opacity-60',
                                )}
                            >
                                <div className="flex items-center justify-between">
                                    <span className="flex items-center gap-1.5 text-sm font-medium">
                                        <span className="text-base">
                                            {g.icon}
                                        </span>
                                        {g.label}
                                    </span>
                                    <span className="font-mono text-xs font-semibold text-primary">
                                        {g.cost} B
                                    </span>
                                </div>
                                {g.ascii && (
                                    <pre
                                        aria-hidden="true"
                                        className="font-mono text-[11px] leading-3.5 whitespace-pre text-primary"
                                    >
                                        {g.ascii}
                                    </pre>
                                )}
                                <p className="line-clamp-2 text-[11px] text-muted-foreground">
                                    {g.description}
                                </p>
                            </button>
                        );
                    })}
                </div>

                {/* Optional Message Field */}
                <div className="flex flex-col gap-1.5">
                    <div className="flex items-center justify-between">
                        <label
                            htmlFor="tip-message"
                            className="font-mono text-xs text-muted-foreground"
                        >
                            Optional message (displayed on tip notification):
                        </label>
                        <span className="font-mono text-[11px] text-muted-foreground">
                            {message.length}/140
                        </span>
                    </div>
                    <textarea
                        id="tip-message"
                        value={message}
                        onChange={(e) =>
                            setMessage(e.target.value.slice(0, 140))
                        }
                        placeholder="e.g. Elegant algorithm, cleanly written!"
                        rows={2}
                        className="w-full resize-none rounded-md border border-input bg-transparent px-3 py-1.5 font-mono text-xs placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>

                {!canAfford && (
                    <p className="rounded-md bg-destructive/10 px-3 py-1.5 font-mono text-xs text-destructive">
                        Insufficient balance for {gift.label} ({gift.cost} Bytes
                        needed). Claim your daily epoch to earn more!
                    </p>
                )}

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={isSubmitting}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={handleSendTip}
                        disabled={!canAfford || isSubmitting}
                        className="gap-1.5 font-mono text-xs"
                    >
                        <span>{gift.icon}</span>
                        <span>
                            {isSubmitting
                                ? 'Sending Tip…'
                                : `Send ${gift.label} (${gift.cost} Bytes)`}
                        </span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
