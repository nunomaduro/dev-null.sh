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
import OrgController from '@/actions/App/Http/Controllers/OrgController';
import { ConversationActions } from '@/components/chat/conversation-actions';
import { ConversationThread } from '@/components/chat/conversation-thread';
import { InboxPanel } from '@/components/chat/inbox-panel';
import { MemberStack, OrgHeader, orgTitle } from '@/components/chat/org';
import { SearchDialog } from '@/components/search-dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserAvatar } from '@/components/user-avatar';
import { useChat, useInbox, useUnreadMessages } from '@/hooks/use-chat';
import { useIsMobile } from '@/hooks/use-mobile';
import { rememberTarget, summaryKey, targetKey, targetOf } from '@/lib/chat';
import type { ChatTarget } from '@/lib/chat';
import { setChatDocked, updateChatDock, useChatDock } from '@/lib/chat-dock';
import { cn, laneColor } from '@/lib/utils';
import { show } from '@/routes/user';
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
    const summary =
        dock.conversation === null
            ? undefined
            : conversations?.find(
                  (conversation) =>
                      summaryKey(conversation) === dock.conversation,
              );
    const target: ChatTarget | null =
        dock.conversation === null
            ? null
            : (threads[dock.conversation]?.target ??
              (summary === undefined ? null : targetOf(summary)));
    const thread = target === null ? undefined : threads[targetKey(target)];

    const open = (next: ChatTarget) => {
        rememberTarget(next);
        setFocusesComposer(true);
        updateChatDock({ conversation: targetKey(next), isExpanded: true });
    };

    const leave = () => updateChatDock({ conversation: null });

    const leaveBlocked = () => {
        leave();
        router.reload();
    };

    const toggle = () => updateChatDock({ isExpanded: !dock.isExpanded });

    const tools = (
        <div className="ml-auto flex shrink-0 items-center">
            {target === null && (
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
                            target === null
                                ? ConversationController.index()
                                : target.kind === 'direct'
                                  ? ConversationController.show(target.partner)
                                  : OrgController.show({ org: target.org.id })
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
                    <span
                        title={`${unread} unread messages`}
                        className="rounded-sm bg-primary px-2 font-mono text-[11px] leading-5 font-medium text-primary-foreground tabular-nums"
                    >
                        <span aria-hidden>
                            {unread > 99 ? '99+' : unread} ahead
                        </span>
                        <span className="sr-only">
                            {unread} unread messages
                        </span>
                    </span>
                )}
            </span>
            {tools}
        </DockHeader>
    );

    return (
        <section
            aria-label="Docked remotes"
            className="fixed right-6 bottom-0 z-30 flex h-(--chat-dock-height) w-[360px] flex-col overflow-hidden rounded-t-lg border border-b-0 bg-card shadow-[0_-12px_40px_-12px_rgb(0_0_0/0.3)] transition-[height] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
            data-chat-dock={dock.isExpanded ? 'expanded' : 'collapsed'}
            data-test="chat-dock"
            data-video-dock-obstacle
        >
            {target !== null && dock.isExpanded ? (
                <ConversationThread
                    key={targetKey(target)}
                    target={target}
                    user={user}
                    isCompact
                    autoFocus={focusesComposer}
                    onDropped={leave}
                    header={
                        <DockHeader onToggle={toggle}>
                            {target.kind === 'org' ? (
                                <OrgHeader
                                    org={target.org}
                                    user={user}
                                    isCompact
                                    before={
                                        <DockButton
                                            label="All remotes"
                                            onClick={leave}
                                            className="-ml-1.5"
                                        >
                                            <ChevronLeft className="size-4" />
                                        </DockButton>
                                    }
                                    after={tools}
                                    onLeft={leave}
                                />
                            ) : (
                                <DirectDockHeader
                                    partner={target.partner}
                                    user={user}
                                    isArchived={thread?.is_archived ?? false}
                                    onBack={leave}
                                    onBlocked={leaveBlocked}
                                    tools={tools}
                                />
                            )}
                        </DockHeader>
                    }
                />
            ) : (
                <>
                    {target !== null ? (
                        <DockHeader onToggle={toggle}>
                            {target.kind === 'direct' ? (
                                <UserAvatar
                                    user={target.partner}
                                    className="size-7"
                                />
                            ) : (
                                <MemberStack
                                    users={(thread?.members ?? []).map(
                                        (member) => member.user,
                                    )}
                                    max={2}
                                    avatarClassName="size-6"
                                />
                            )}
                            <span className="min-w-0 truncate text-sm font-semibold">
                                {target.kind === 'direct'
                                    ? target.partner.name
                                    : orgTitle(
                                          target.org,
                                          thread?.members ??
                                              summary?.members ??
                                              null,
                                          user,
                                      )}
                            </span>
                            {unread > 0 && (
                                <span
                                    title={`${unread} unread messages`}
                                    className="rounded-sm bg-primary px-2 font-mono text-[11px] leading-5 font-medium text-primary-foreground tabular-nums"
                                >
                                    <span aria-hidden>
                                        {unread > 99 ? '99+' : unread}
                                    </span>
                                    <span className="sr-only">
                                        {unread} unread messages
                                    </span>
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
                onPickDeveloper={(person) =>
                    open({ kind: 'direct', partner: person })
                }
                excludeId={user.id}
            />
        </section>
    );
}

function DirectDockHeader({
    partner,
    user,
    isArchived,
    onBack,
    onBlocked,
    tools,
}: {
    partner: User;
    user: User;
    isArchived: boolean;
    onBack: () => void;
    onBlocked: () => void;
    tools: ReactNode;
}) {
    return (
        <>
            <DockButton
                label="All remotes"
                onClick={onBack}
                className="-ml-1.5"
            >
                <ChevronLeft className="size-4" />
            </DockButton>
            <UserAvatar
                user={partner}
                className="size-7 ring-2 ring-(--lane)"
                style={
                    { '--lane': laneColor(partner.lane) } as React.CSSProperties
                }
            />
            <Link
                href={show(partner)}
                className="group min-w-0 rounded-md"
                title={partner.name}
            >
                <span className="block truncate text-sm font-semibold group-hover:underline">
                    {partner.name}
                </span>
                <span className="block truncate font-mono text-[11px] text-muted-foreground">
                    @{partner.username}
                </span>
            </Link>
            <ConversationActions
                target={{ kind: 'direct', partner }}
                title={`@${partner.username}`}
                user={user}
                isArchived={isArchived}
                onLeave={onBack}
                onBlocked={onBlocked}
                className="ml-auto"
            />
            {tools}
        </>
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
                const target = event.target as HTMLElement;

                // Clicks inside dialogs and menus opened from the header bubble here through their portals.
                if (
                    event.currentTarget.contains(target) &&
                    !target.closest('button, a')
                ) {
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
