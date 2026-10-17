import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, PanelBottomOpen, SquarePen } from 'lucide-react';
import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import { ConversationActions } from '@/components/chat/conversation-actions';
import { ConversationThread } from '@/components/chat/conversation-thread';
import { InboxPanel } from '@/components/chat/inbox-panel';
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
import { seedInbox, seedThread } from '@/lib/chat';
import { setChatDocked, useChatDock } from '@/lib/chat-dock';
import { cn } from '@/lib/utils';
import { show } from '@/routes/user';
import type { InboxPage, Thread, User } from '@/types';

export default function Index({
    inbox,
    partner,
    thread,
}: {
    inbox: InboxPage;
    partner: User | null;
    thread: Thread | null;
}) {
    const { user } = useUser();
    const dock = useChatDock();
    const [isPicking, setIsPicking] = useState(false);
    const [seededFrom, setSeededFrom] = useState<object | null>(null);

    if (seededFrom !== inbox) {
        setSeededFrom(inbox);
        seedInbox(inbox);

        if (partner !== null && thread !== null) {
            seedThread(partner, thread);
        }
    }

    useInbox();

    // Keeps the inbox's tab and search while moving between conversations.
    const visit = (href: Parameters<typeof router.visit>[0]) =>
        router.visit(href, { preserveScroll: true, preserveState: true });

    const message = (person: User) =>
        visit(ConversationController.show(person));

    return (
        <>
            <Head
                title={partner ? `@${partner.username} · Remotes` : 'Remotes'}
            />

            <div className="flex h-[calc(100dvh-8.5rem)] min-h-0 md:h-svh">
                <section
                    aria-label="Conversations"
                    className={cn(
                        'flex min-h-0 w-full flex-col lg:w-80 lg:shrink-0 lg:border-r',
                        { 'hidden lg:flex': partner !== null },
                    )}
                >
                    <div className="flex h-16 shrink-0 items-center justify-between gap-2 px-4">
                        <h1 className="text-xl font-bold tracking-tight">
                            Remotes
                        </h1>
                        <div className="flex items-center gap-1">
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-pressed={dock.isDocked}
                                        onClick={() =>
                                            setChatDocked(!dock.isDocked)
                                        }
                                        className={cn(
                                            'hidden h-8 min-w-24 items-center justify-center gap-1.5 rounded-full border px-3 text-[13px] font-medium text-muted-foreground transition-colors hover:border-input hover:text-foreground md:inline-flex',
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
                                        aria-label="New remote"
                                        onClick={() => setIsPicking(true)}
                                        className="grid size-9 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
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
                        activeUsername={partner?.username}
                        onOpen={message}
                        onLeaveActive={() =>
                            visit(ConversationController.index())
                        }
                        onBlocked={() => router.reload({ only: ['inbox'] })}
                        empty={
                            <EmptyInbox onCompose={() => setIsPicking(true)} />
                        }
                    />
                </section>

                {partner !== null ? (
                    <ConversationThread
                        key={partner.username}
                        partner={partner}
                        user={user}
                        header={
                            <ThreadHeader
                                partner={partner}
                                onLeave={() =>
                                    visit(ConversationController.index())
                                }
                            />
                        }
                        autoFocus
                        onDropped={() => visit(ConversationController.index())}
                    />
                ) : (
                    <div className="hidden flex-1 flex-col items-center justify-center gap-3 px-8 text-center lg:flex">
                        <p className="font-mono text-[13px] text-muted-foreground">
                            no conversation checked out
                        </p>
                        <Button
                            variant="outline"
                            className="rounded-full"
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
        </>
    );
}

function ThreadHeader({
    partner,
    onLeave,
}: {
    partner: User;
    onLeave: () => void;
}) {
    const { threads } = useChat();

    return (
        <header className="flex h-16 shrink-0 items-center gap-3 border-b px-4">
            <Link
                href={ConversationController.index()}
                aria-label="All remotes"
                className="-ml-2 grid size-9 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground lg:hidden"
            >
                <ChevronLeft className="size-5" />
            </Link>
            <Link
                href={show(partner)}
                className="flex min-w-0 items-center gap-3 rounded-lg"
            >
                <UserAvatar user={partner} className="size-9" />
                <span className="min-w-0">
                    <span
                        className="block truncate text-[15px] font-semibold hover:underline"
                        title={partner.name}
                    >
                        {partner.name}
                    </span>
                    <span className="block truncate font-mono text-xs text-muted-foreground">
                        @{partner.username}
                    </span>
                </span>
            </Link>
            <ConversationActions
                partner={partner}
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
            <Button
                variant="outline"
                className="rounded-full"
                onClick={onCompose}
            >
                <SquarePen className="size-4" />
                New remote
            </Button>
        </div>
    );
}

Index.layout = AppLayout;
