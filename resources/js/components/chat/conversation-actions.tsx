import { Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Ban,
    Ellipsis,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { blockPartner, setConversationArchived } from '@/lib/chat';
import { cn } from '@/lib/utils';
import { show } from '@/routes/user';
import type { User } from '@/types';

/**
 * Stash (archive), pop (unarchive), or block, from the conversation's own header or from its row in the inbox.
 */
export function ConversationActions({
    partner,
    isArchived,
    onLeave,
    onBlocked,
    onOpenChange,
    className,
}: {
    partner: User;
    isArchived: boolean;
    /**
     * Called once the conversation leaves the list being shown, so an open thread can step back to the inbox.
     */
    onLeave?: () => void;
    /**
     * Called after blocking, once the conversation is gone, instead of onLeave when given. It should make an
     * Inertia visit, which brings the server's confirmation toast.
     */
    onBlocked?: () => void;
    onOpenChange?: (isOpen: boolean) => void;
    className?: string;
}) {
    const [isMenuOpen, setIsMenuOpen] = useState(false);
    const [isConfirmingBlock, setIsConfirmingBlock] = useState(false);
    const [isBlocking, setIsBlocking] = useState(false);

    const changeOpen = (menu: boolean, dialog: boolean) => {
        setIsMenuOpen(menu);
        setIsConfirmingBlock(dialog);
        onOpenChange?.(menu || dialog);
    };

    const archive = async () => {
        const archiving = !isArchived;

        onLeave?.();

        if (!(await setConversationArchived(partner, archiving))) {
            toast.error('Could not update the conversation. Try again.');

            return;
        }

        toast.success(
            archiving
                ? `Stashed your conversation with @${partner.username}. It pops back when either of you writes.`
                : `Popped your conversation with @${partner.username} back into Open.`,
        );
    };

    const block = async () => {
        setIsBlocking(true);
        const isBlocked = await blockPartner(partner);
        setIsBlocking(false);
        changeOpen(false, false);

        if (!isBlocked) {
            toast.error(`Could not block @${partner.username}. Try again.`);

            return;
        }

        router.flushAll();
        (onBlocked ?? onLeave)?.();
    };

    return (
        <>
            <DropdownMenu
                modal={false}
                open={isMenuOpen}
                onOpenChange={(open) => changeOpen(open, isConfirmingBlock)}
            >
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={`More options for your conversation with @${partner.username}`}
                        onClick={(event) => event.stopPropagation()}
                        className={cn(
                            'grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground',
                            className,
                        )}
                        data-test="conversation-actions"
                    >
                        <Ellipsis className="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    className="w-56"
                    onClick={(event) => event.stopPropagation()}
                >
                    <DropdownMenuItem asChild>
                        <Link href={show(partner)}>
                            <UserRound />
                            View profile
                        </Link>
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => void archive()}>
                        {isArchived ? <ArchiveRestore /> : <Archive />}
                        {isArchived ? 'Pop' : 'Stash'}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => changeOpen(false, true)}
                    >
                        <Ban />
                        Block @{partner.username}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog
                open={isConfirmingBlock}
                onOpenChange={(open) => changeOpen(false, open)}
            >
                <DialogContent onClick={(event) => event.stopPropagation()}>
                    <DialogTitle>Block @{partner.username}?</DialogTitle>
                    <DialogDescription>
                        This conversation goes away for both of you, and neither
                        of you can message, follow, reply to, or star the other.
                        They won't be told. You can unblock them any time from
                        your settings.
                    </DialogDescription>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={isBlocking}
                            onClick={() => void block()}
                            className="min-w-20"
                            data-test="confirm-block-button"
                        >
                            Block
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
