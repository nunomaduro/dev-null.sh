import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { Archive, ArrowLeft, Volume2, VolumeX } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import ConversationPreferencesController from '@/actions/App/Http/Controllers/ConversationPreferencesController';
import ConversationReadController from '@/actions/App/Http/Controllers/ConversationReadController';
import ConversationRequestController from '@/actions/App/Http/Controllers/ConversationRequestController';
import UserBlockController from '@/actions/App/Http/Controllers/UserBlockController';
import UserController from '@/actions/App/Http/Controllers/UserController';
import { MessageComposer } from '@/components/message-composer';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { UserAvatar } from '@/components/user-avatar';
import AppLayout from '@/layouts/app-layout';
import type {
    Conversation,
    DirectMessage,
    MessagePage,
} from '@/types/messaging';

export default function Show({
    conversation,
    messages,
    isOlderPage,
}: {
    conversation: Conversation;
    messages: MessagePage<DirectMessage>;
    isOlderPage: boolean;
}) {
    const { auth } = usePage().props;
    const [busy, setBusy] = useState(false);
    const [blocking, setBlocking] = useState(false);
    const readThrough = useRef(0);
    const messageList = useRef<HTMLOListElement>(null);
    const newestMessage = useRef<HTMLLIElement>(null);
    const followingBottom = useRef(true);
    const [newMessagesIn, setNewMessagesIn] = useState<string | null>(null);
    const latest = messages.data[0]?.id ?? 0;
    const { start, stop } = usePoll(
        5000,
        { only: ['conversation', 'messages', 'auth.unreadMessagesCount'] },
        { mode: 'rest', background: 'pause', autoStart: !isOlderPage },
    );

    useEffect(() => {
        if (isOlderPage) stop();
        else start();
    }, [isOlderPage, start, stop]);

    useLayoutEffect(() => {
        readThrough.current = 0;
        followingBottom.current = true;
    }, [conversation.id]);

    useLayoutEffect(() => {
        const list = messageList.current;
        if (list && followingBottom.current) {
            list.scrollTop = list.scrollHeight;
        } else {
            setNewMessagesIn(conversation.id);
        }
    }, [conversation.id, latest]);

    useEffect(() => {
        let visible = false;
        const read = () => {
            if (
                !visible ||
                document.visibilityState !== 'visible' ||
                !document.hasFocus() ||
                latest <= readThrough.current
            )
                return;
            readThrough.current = latest;
            router.put(
                ConversationReadController.update(conversation.id).url,
                { through: latest },
                {
                    preserveScroll: true,
                    only: [
                        'auth.unreadMessagesCount',
                        'auth.unreadNotificationsCount',
                    ],
                    onError: () => {
                        readThrough.current = 0;
                    },
                },
            );
        };
        const observer = new IntersectionObserver(
            ([entry]) => {
                visible = entry.isIntersecting;
                read();
            },
            { root: messageList.current, threshold: 0.1 },
        );
        if (newestMessage.current) observer.observe(newestMessage.current);
        window.addEventListener('focus', read);
        document.addEventListener('visibilitychange', read);
        return () => {
            observer.disconnect();
            window.removeEventListener('focus', read);
            document.removeEventListener('visibilitychange', read);
        };
    }, [conversation.id, latest]);

    const preferences = (muted: boolean, archived: boolean) => {
        setBusy(true);
        router.put(
            ConversationPreferencesController.update(conversation.id).url,
            { muted, archived },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    if (archived) router.visit(ConversationController.index());
                },
            },
        );
    };
    const decide = (status: 'accepted' | 'declined') => {
        setBusy(true);
        router.put(
            ConversationRequestController.update(conversation.id).url,
            { status },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    if (status === 'declined')
                        router.visit(
                            ConversationController.index({
                                query: { folder: 'requests' },
                            }),
                        );
                },
            },
        );
    };
    return (
        <>
            <Head title={`Messages with @${conversation.other.username}`} />
            <header className="sticky top-0 z-10 border-b bg-card">
                <div className="flex h-16 items-center gap-3 px-4 sm:px-6">
                    <Link
                        href={ConversationController.index()}
                        aria-label="Back to messages"
                        className="shrink-0 rounded-md p-2 hover:bg-accent"
                    >
                        <ArrowLeft className="size-5" />
                    </Link>
                    <UserAvatar
                        user={conversation.other}
                        className="size-9 shrink-0"
                    />
                    <Link
                        href={UserController.show(conversation.other)}
                        className="min-w-0"
                    >
                        <h1 className="truncate font-semibold">
                            {conversation.other.name}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            @{conversation.other.username}
                        </p>
                    </Link>
                </div>
                <div className="flex flex-wrap items-center gap-2 px-4 pb-3 sm:px-6">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() =>
                            preferences(
                                !conversation.muted,
                                conversation.archived,
                            )
                        }
                        className="min-w-24"
                    >
                        {conversation.muted ? <Volume2 /> : <VolumeX />}
                        {conversation.muted ? 'Unmute' : 'Mute'}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        onClick={() =>
                            preferences(
                                conversation.muted,
                                !conversation.archived,
                            )
                        }
                    >
                        <Archive />
                        {conversation.archived ? 'Unarchive' : 'Archive'}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setBlocking(true)}
                    >
                        Block
                    </Button>
                </div>
            </header>
            {conversation.incoming_request && (
                <section className="border-b bg-muted/40 px-4 py-5 sm:px-6">
                    <h2 className="font-medium">Message request</h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Accept to allow this person to send more messages. They
                        cannot see whether you have read this request.
                    </p>
                    <div className="mt-4 flex gap-3">
                        <Button
                            disabled={busy || !conversation.can_accept}
                            onClick={() => decide('accepted')}
                        >
                            Accept
                        </Button>
                        <Button
                            disabled={busy}
                            variant="outline"
                            onClick={() => decide('declined')}
                        >
                            Decline
                        </Button>
                    </div>
                </section>
            )}
            <div className="flex justify-between gap-3 p-4">
                {messages.next_page_url && (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={messages.next_page_url}>
                            Older messages
                        </Link>
                    </Button>
                )}
                {isOlderPage && (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={ConversationController.show(conversation.id)}
                        >
                            Latest messages
                        </Link>
                    </Button>
                )}
            </div>
            <div className="relative">
                <ol
                    ref={messageList}
                    aria-label="Messages"
                    tabIndex={0}
                    onScroll={() => {
                        const list = messageList.current;
                        if (list) {
                            followingBottom.current =
                                list.scrollHeight -
                                    list.scrollTop -
                                    list.clientHeight <
                                60;
                            if (followingBottom.current) setNewMessagesIn(null);
                        }
                    }}
                    className="h-[max(8rem,calc(100dvh-35rem))] space-y-4 overflow-y-auto px-4 pb-6 sm:px-6 md:h-[max(8rem,calc(100dvh-25rem))]"
                >
                    {[...messages.data].reverse().map((message) => {
                        const mine = message.sender_id === auth.user?.id;
                        return (
                            <li
                                key={message.id}
                                ref={
                                    message.id === latest
                                        ? newestMessage
                                        : undefined
                                }
                                className={`flex flex-col ${mine ? 'items-end' : 'items-start'}`}
                            >
                                <div
                                    className={`max-w-[90%] rounded-xl border px-4 py-3 ${mine ? 'border-primary/20 bg-primary-soft' : 'bg-muted/40'}`}
                                >
                                    <p className="text-[15px] wrap-anywhere whitespace-pre-wrap">
                                        {message.body}
                                    </p>
                                </div>
                                <p className="mt-1 flex gap-2 text-xs text-muted-foreground">
                                    <time dateTime={message.created_at}>
                                        {new Date(
                                            message.created_at,
                                        ).toLocaleString()}
                                    </time>
                                    {mine && (
                                        <span>
                                            {conversation.read_through !==
                                                null &&
                                            message.id <=
                                                conversation.read_through
                                                ? 'Read'
                                                : 'Sent'}
                                        </span>
                                    )}
                                </p>
                            </li>
                        );
                    })}
                </ol>
                {newMessagesIn === conversation.id && (
                    <Button
                        size="sm"
                        className="absolute bottom-3 left-1/2 -translate-x-1/2"
                        onClick={() => {
                            const list = messageList.current;
                            if (list) list.scrollTop = list.scrollHeight;
                            followingBottom.current = true;
                            setNewMessagesIn(null);
                        }}
                    >
                        New messages ↓
                    </Button>
                )}
            </div>
            {conversation.can_send ? (
                <MessageComposer
                    key={conversation.id}
                    recipient={conversation.other}
                />
            ) : (
                <p className="border-t px-4 py-6 text-sm text-muted-foreground sm:px-6">
                    {conversation.status === 'requested' &&
                    !conversation.incoming_request
                        ? 'Your request was sent. You can send more messages once it is accepted.'
                        : 'Sending is unavailable. Check messaging settings or accept the request to continue.'}
                </p>
            )}
            <Dialog open={blocking} onOpenChange={setBlocking}>
                <DialogContent>
                    <DialogTitle>
                        Block @{conversation.other.username}?
                    </DialogTitle>
                    <DialogDescription>
                        This stops messages in both directions and removes your
                        follows. You can unblock this person in Safety settings.
                    </DialogDescription>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setBlocking(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={busy}
                            onClick={() => {
                                setBusy(true);
                                router.post(
                                    UserBlockController.store(
                                        conversation.other,
                                    ).url,
                                    { return_to_messages: true },
                                    {
                                        onFinish: () => {
                                            setBusy(false);
                                            setBlocking(false);
                                        },
                                    },
                                );
                            }}
                        >
                            Block
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
Show.layout = [AppLayout];
