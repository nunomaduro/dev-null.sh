import { Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronUp,
    Maximize2,
    Minus,
    SquarePen,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import { ConversationActions } from '@/components/chat/conversation-actions';
import { ConversationThread } from '@/components/chat/conversation-thread';
import { InboxPanel } from '@/components/chat/inbox-panel';
import { SearchDialog } from '@/components/search-dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserAvatar } from '@/components/user-avatar';
import { useChat, useInbox, useUnreadMessages } from '@/hooks/use-chat';
import { useIsMobile } from '@/hooks/use-mobile';
import { rememberPartner } from '@/lib/chat';
import { setChatDocked, updateChatDock, useChatDock } from '@/lib/chat-dock';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

/**
 * The whole inbox, docked to the bottom of every page once the user turns it on from Remotes. Phones never
 * dock: there is no room beside the page.
 */
export function ChatDock({ user }: { user: User }) {
    const dock = useChatDock();
    const isMobile = useIsMobile();

    if (!dock.isDocked || isMobile) {
        return null;
    }

    return <DockedChat user={user} />;
}

function DockedChat({ user }: { user: User }) {
    const dock = useChatDock();
    const conversations = useInbox();
    const { threads } = useChat();
    const unread = useUnreadMessages();
    const [isPicking, setIsPicking] = useState(false);
    const [focusesComposer, setFocusesComposer] = useState(false);
    const partner =
        dock.partner === null
            ? null
            : (conversations?.find(
                  (conversation) =>
                      conversation.partner.username === dock.partner,
              )?.partner ??
              threads[dock.partner]?.partner ??
              null);

    const open = (person: User) => {
        rememberPartner(person);
        setFocusesComposer(true);
        updateChatDock({ partner: person.username, isExpanded: true });
    };

    const leave = () => updateChatDock({ partner: null });

    const leaveBlocked = () => {
        leave();
        router.reload();
    };

    const toggle = () => updateChatDock({ isExpanded: !dock.isExpanded });

    const tools = (
        <div className="ml-auto flex shrink-0 items-center">
            {partner === null && (
                <DockButton
                    label="New remote"
                    onClick={() => setIsPicking(true)}
                >
                    <SquarePen className="size-4" />
                </DockButton>
            )}
            <Tooltip>
                <TooltipTrigger asChild>
                    <Link
                        href={
                            partner === null
                                ? ConversationController.index()
                                : ConversationController.show(partner)
                        }
                        onClick={(event) => event.stopPropagation()}
                        aria-label="Open in Remotes"
                        className="grid size-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        <Maximize2 className="size-4" />
                    </Link>
                </TooltipTrigger>
                <TooltipContent>Open in Remotes</TooltipContent>
            </Tooltip>
            <DockButton
                label={dock.isExpanded ? 'Minimize' : 'Expand'}
                onClick={toggle}
            >
                {dock.isExpanded ? (
                    <Minus className="size-4" />
                ) : (
                    <ChevronUp className="size-4" />
                )}
            </DockButton>
            <DockButton label="Undock" onClick={() => setChatDocked(false)}>
                <X className="size-4" />
            </DockButton>
        </div>
    );

    const listHeader = (
        <DockHeader onToggle={toggle}>
            <span className="flex min-w-0 items-center gap-2">
                <span className="text-[15px] font-bold">Remotes</span>
                {unread > 0 && (
                    <span className="rounded-full bg-primary px-2 font-mono text-[11px] leading-5 font-medium text-primary-foreground tabular-nums">
                        {unread} ahead
                    </span>
                )}
            </span>
            {tools}
        </DockHeader>
    );

    return (
        <section
            aria-label="Docked remotes"
            className={cn(
                'fixed right-6 bottom-0 z-30 flex w-[360px] flex-col overflow-hidden rounded-t-xl border border-b-0 bg-card shadow-[0_-12px_40px_-12px_rgb(0_0_0/0.3)] transition-[height] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]',
                dock.isExpanded ? 'h-[min(520px,calc(100dvh-5rem))]' : 'h-12',
            )}
            data-test="chat-dock"
        >
            {partner !== null && dock.isExpanded ? (
                <ConversationThread
                    key={partner.username}
                    partner={partner}
                    user={user}
                    isCompact
                    autoFocus={focusesComposer}
                    onDropped={leave}
                    header={
                        <DockHeader onToggle={toggle}>
                            <DockButton
                                label="All remotes"
                                onClick={() =>
                                    updateChatDock({ partner: null })
                                }
                                className="-ml-1.5"
                            >
                                <ChevronLeft className="size-4" />
                            </DockButton>
                            <UserAvatar user={partner} className="size-7" />
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-semibold">
                                    {partner.name}
                                </span>
                                <span className="block truncate font-mono text-[11px] text-muted-foreground">
                                    @{partner.username}
                                </span>
                            </span>
                            <ConversationActions
                                partner={partner}
                                isArchived={
                                    threads[partner.username]?.is_archived ??
                                    false
                                }
                                onLeave={leave}
                                onBlocked={leaveBlocked}
                                className="ml-auto"
                            />
                            {tools}
                        </DockHeader>
                    }
                />
            ) : (
                <>
                    {partner !== null ? (
                        <DockHeader onToggle={toggle}>
                            <UserAvatar user={partner} className="size-7" />
                            <span className="min-w-0 truncate text-sm font-semibold">
                                {partner.name}
                            </span>
                            {unread > 0 && (
                                <span className="rounded-full bg-primary px-2 font-mono text-[11px] leading-5 font-medium text-primary-foreground tabular-nums">
                                    {unread}
                                </span>
                            )}
                            {tools}
                        </DockHeader>
                    ) : (
                        listHeader
                    )}
                    {dock.isExpanded && (
                        <div className="flex min-h-0 flex-1 flex-col pt-2.5">
                            <InboxPanel
                                user={user}
                                onOpen={open}
                                onBlocked={() => router.reload()}
                                isCompact
                                empty={
                                    <p className="px-4 py-10 text-center font-mono text-[13px] text-muted-foreground">
                                        no conversations yet
                                    </p>
                                }
                            />
                        </div>
                    )}
                </>
            )}

            <SearchDialog
                open={isPicking}
                onOpenChange={setIsPicking}
                onPickDeveloper={open}
                excludeId={user.id}
            />
        </section>
    );
}

function DockHeader({
    onToggle,
    children,
}: {
    onToggle: () => void;
    children: ReactNode;
}) {
    return (
        <header
            onClick={(event) => {
                if (!(event.target as HTMLElement).closest('button, a')) {
                    onToggle();
                }
            }}
            className="flex h-12 shrink-0 cursor-pointer items-center gap-2 border-b px-3"
        >
            {children}
        </header>
    );
}

function DockButton({
    label,
    onClick,
    className,
    children,
}: {
    label: string;
    onClick: () => void;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    onClick={(event) => {
                        event.stopPropagation();
                        onClick();
                    }}
                    className={cn(
                        'grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground',
                        className,
                    )}
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
