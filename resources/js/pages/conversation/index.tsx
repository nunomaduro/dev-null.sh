import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    PanelBottomOpen,
    SquarePen,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import OrgController from '@/actions/App/Http/Controllers/OrgController';
import { ConversationActions } from '@/components/chat/conversation-actions';
import { ConversationThread } from '@/components/chat/conversation-thread';
import { InboxPanel } from '@/components/chat/inbox-panel';
import { RemoteLocalTime } from '@/components/chat/remote-local-time';
import { OrgHeader, OrgPeopleDialog } from '@/components/chat/org';
import { RemoteSettingsMenu } from '@/components/chat/remote-settings-menu';
import { SearchDialog } from '@/components/search-dialog';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserAvatar } from '@/components/user-avatar';
import { useChat, useInbox } from '@/hooks/use-chat';
import { useUser } from '@/hooks/use-user';
import AppLayout from '@/layouts/app-layout';
import { seedInbox, seedThread, targetKey } from '@/lib/chat';
import type { ChatTarget } from '@/lib/chat';
import { setChatDocked, useChatDock } from '@/lib/chat-dock';
import { cn, laneColor } from '@/lib/utils';
import { show } from '@/routes/user';
import type { InboxPage, OrgRef, OrgThread, Thread, User } from '@/types';

export default function Index({
    inbox,
    partner,
    org = null,
    thread,
}: {
    inbox: InboxPage;
    partner: User | null;
    org?: OrgRef | null;
    thread: Thread | OrgThread | null;
}) {
    const { user } = useUser();
    const dock = useChatDock();
    const [isPicking, setIsPicking] = useState(false);
    const [isStartingOrg, setIsStartingOrg] = useState(false);
    const [seededFrom, setSeededFrom] = useState<object | null>(null);
    const target: ChatTarget | null =
        partner !== null
            ? { kind: 'direct', partner }
            : org !== null
              ? { kind: 'org', org }
              : null;

    if (seededFrom !== inbox) {
        setSeededFrom(inbox);
        seedInbox(inbox);

        if (target !== null && thread !== null) {
            seedThread(target, thread);
        }
    }

    useInbox();

    // Keeps the inbox's tab and search while moving between conversations.
    const visit = (href: Parameters<typeof router.visit>[0]) =>
        router.visit(href, { preserveScroll: true, preserveState: true });

    const open = (next: ChatTarget) =>
        visit(
            next.kind === 'direct'
                ? ConversationController.show(next.partner)
                : OrgController.show({ org: next.org.id }),
        );

    const message = (person: User) => open({ kind: 'direct', partner: person });

    const leave = () => visit(ConversationController.index());

    return (
        <>
            <Head
                title={
                    partner
                        ? `@${partner.username} · Remotes`
                        : org
                          ? `${org.name ?? 'Org'} · Remotes`
                          : 'Remotes'
                }
            />

            {/* On phones the open keyboard scrolls the page under it, so the chat follows what is still visible. */}
            <div className="flex min-h-0 bg-card max-md:fixed max-md:inset-x-0 max-md:top-[max(3.5rem,var(--visual-viewport-top,0px))] max-md:bottom-[max(var(--mobile-nav-height,4.5rem),var(--visual-viewport-bottom,0px))] md:h-svh max-md:[:root[data-keyboard-open]_&]:bottom-(--visual-viewport-bottom)">
                <section
                    aria-label="Conversations"
                    className={cn(
                        'flex min-h-0 w-full flex-col lg:w-80 lg:shrink-0 lg:border-r',
                        { 'hidden lg:flex': target !== null },
                    )}
                >
                    <div className="flex h-16 shrink-0 items-center justify-between gap-2 px-4">
                        <h1 className="text-xl font-bold tracking-tight">
                            Remotes
                        </h1>
                        <div className="flex items-center gap-0.5">
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-pressed={dock.isDocked}
                                        onClick={() =>
                                            setChatDocked(!dock.isDocked)
                                        }
                                        className={cn(
                                            'mr-0.5 hidden h-8 min-w-22 items-center justify-center gap-1 rounded-md border px-2.5 text-[13px] font-medium text-muted-foreground transition-colors hover:border-input hover:text-foreground md:inline-flex',
                                            {
                                                'border-transparent bg-primary-soft text-primary hover:border-transparent hover:text-primary':
                                                    dock.isDocked,
                                            },
                                        )}
                                        data-test="chat-dock-toggle"
                                    >
                                        <PanelBottomOpen className="size-4" />
                                        {dock.isDocked ? 'Docked' : 'Dock'}
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {dock.isDocked
                                        ? 'Undock chat'
                                        : 'Keep chat open on every page'}
                                </TooltipContent>
                            </Tooltip>
                            <RemoteSettingsMenu />
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-label="New org"
                                        onClick={() => setIsStartingOrg(true)}
                                        className="grid size-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                        data-test="new-org"
                                    >
                                        <UsersRound className="size-[18px]" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>New org</TooltipContent>
                            </Tooltip>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-label="New remote"
                                        onClick={() => setIsPicking(true)}
                                        className="grid size-8 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                        data-test="new-message"
                                    >
                                        <SquarePen className="size-[18px]" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>New remote</TooltipContent>
                            </Tooltip>
                        </div>
                    </div>

                    <InboxPanel
                        user={user}
                        activeKey={target === null ? null : targetKey(target)}
                        onOpen={open}
                        onLeaveActive={leave}
                        onBlocked={() => router.reload({ only: ['inbox'] })}
                        empty={
                            <EmptyInbox onCompose={() => setIsPicking(true)} />
                        }
                    />
                </section>

                {target !== null ? (
                    <ConversationThread
                        key={targetKey(target)}
                        target={target}
                        user={user}
                        header={
                            target.kind === 'direct' ? (
                                <ThreadHeader
                                    partner={target.partner}
                                    user={user}
                                    onLeave={leave}
                                />
                            ) : (
                                <header className="flex h-16 shrink-0 items-center gap-3 border-b px-4">
                                    <OrgHeader
                                        org={target.org}
                                        user={user}
                                        before={<BackToRemotes />}
                                        onLeft={leave}
                                    />
                                </header>
                            )
                        }
                        autoFocus
                        onDropped={leave}
                    />
                ) : (
                    <div className="hidden flex-1 flex-col items-center justify-center gap-3 px-8 text-center lg:flex">
                        <p className="font-mono text-[13px] text-muted-foreground">
                            no conversation checked out
                        </p>
                        <Button
                            variant="outline"
                            onClick={() => setIsPicking(true)}
                        >
                            <SquarePen className="size-4" />
                            New remote
                        </Button>
                    </div>
                )}
            </div>

            <SearchDialog
                open={isPicking}
                onOpenChange={setIsPicking}
                onPickDeveloper={message}
                excludeId={user.id}
            />

            <OrgPeopleDialog
                mode={{ kind: 'create' }}
                open={isStartingOrg}
                onOpenChange={setIsStartingOrg}
                onDone={(created) => open({ kind: 'org', org: created })}
            />
        </>
    );
}

function BackToRemotes() {
    return (
        <Link
            href={ConversationController.index()}
            aria-label="All remotes"
            className="-ml-2 grid size-9 shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground lg:hidden"
        >
            <ChevronLeft className="size-5" />
        </Link>
    );
}

function ThreadHeader({
    partner,
    user,
    onLeave,
}: {
    partner: User;
    user: User;
    onLeave: () => void;
}) {
    const { threads } = useChat();

    return (
        <header className="flex h-16 shrink-0 items-center gap-3 border-b px-4">
            <BackToRemotes />
            <Link
                href={show(partner)}
                className="flex min-w-0 items-center gap-3 rounded-lg"
            >
                <UserAvatar
                    user={partner}
                    className="size-9 ring-2 ring-(--lane)"
                    style={
                        {
                            '--lane': laneColor(partner.lane),
                        } as React.CSSProperties
                    }
                />
                <div className="min-w-0">
                    <span
                        className="block truncate text-[15px] font-semibold hover:underline"
                        title={partner.name}
                    >
                        {partner.name}
                    </span>
                    <span className="block truncate font-mono text-xs text-muted-foreground">
                        @{partner.username}
                    </span>
                    <RemoteLocalTime partner={partner} />
                </div>
            </Link>
            <ConversationActions
                target={{ kind: 'direct', partner }}
                title={`@${partner.username}`}
                user={user}
                isArchived={threads[partner.username]?.is_archived ?? false}
                onLeave={onLeave}
                className="ml-auto size-9"
            />
        </header>
    );
}

function EmptyInbox({ onCompose }: { onCompose: () => void }) {
    return (
        <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <p className="font-mono text-[13px] text-muted-foreground">
                no conversations yet
            </p>
            <Button variant="outline" onClick={onCompose}>
                <SquarePen className="size-4" />
                New remote
            </Button>
        </div>
    );
}

Index.layout = AppLayout;
