import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Mail, Plus, Settings2, VolumeX } from 'lucide-react';
import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import MessagingSettingsController from '@/actions/App/Http/Controllers/MessagingSettingsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { UserAvatar } from '@/components/user-avatar';
import AppLayout from '@/layouts/app-layout';
import type { Conversation, MessagePage } from '@/types/messaging';

type Filters = { folder: 'inbox' | 'requests' | 'archived'; search: string };

export default function Index({
    conversations,
    filters,
    enabled,
}: {
    conversations: MessagePage<Conversation>;
    filters: Filters;
    enabled: boolean;
}) {
    const [search, setSearch] = useState(filters.search);
    usePoll(
        10_000,
        { only: ['conversations', 'auth.unreadMessagesCount', 'enabled'] },
        { mode: 'rest', background: 'pause' },
    );
    return (
        <>
            <Head title="Messages" />
            <header className="sticky top-0 z-10 border-b bg-card">
                <div className="flex h-14 items-center justify-between gap-2 px-4 sm:px-6">
                    <h1 className="font-semibold">Messages</h1>
                    <div className="flex gap-2">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={MessagingSettingsController.edit()}
                                aria-label="Messaging settings"
                            >
                                <Settings2 />
                            </Link>
                        </Button>
                        <Button size="sm" asChild>
                            <Link href={ConversationController.create()}>
                                <Plus />
                                New message
                            </Link>
                        </Button>
                    </div>
                </div>
                <nav aria-label="Message folders" className="flex px-4">
                    {(['inbox', 'requests', 'archived'] as const).map(
                        (folder) => (
                            <Link
                                key={folder}
                                href={ConversationController.index({
                                    query: { folder },
                                })}
                                className={`flex-1 border-b-2 px-2 py-3 text-center text-sm capitalize ${filters.folder === folder ? 'border-primary font-semibold text-primary' : 'border-transparent text-muted-foreground'}`}
                                aria-current={
                                    filters.folder === folder
                                        ? 'page'
                                        : undefined
                                }
                            >
                                {folder}
                            </Link>
                        ),
                    )}
                </nav>
            </header>
            {!enabled && (
                <div className="border-b bg-muted/40 px-4 py-4 text-sm sm:px-6">
                    Direct messages are off.{' '}
                    <Link
                        href={MessagingSettingsController.edit()}
                        className="font-medium text-primary underline"
                    >
                        Choose who can message you
                    </Link>{' '}
                    to get started.
                </div>
            )}
            <form
                className="border-b p-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(
                        ConversationController.index().url,
                        { ...filters, search },
                        { preserveState: true },
                    );
                }}
            >
                <label htmlFor="conversation-search" className="sr-only">
                    Search conversations by username
                </label>
                <div className="flex gap-2">
                    <Input
                        id="conversation-search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search conversations by username"
                        className="text-base"
                    />
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </div>
            </form>
            {conversations.data.length === 0 ? (
                <div className="px-6 py-16 text-center">
                    <Mail className="mx-auto mb-4 size-8 text-muted-foreground" />
                    <h2 className="font-medium">
                        {filters.folder === 'requests'
                            ? 'No message requests'
                            : 'No conversations here yet'}
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {filters.folder === 'requests'
                            ? 'Requests from people you do not follow will appear here.'
                            : 'Start a conversation from a developer’s profile or choose New message.'}
                    </p>
                </div>
            ) : (
                <ul className="divide-y">
                    {conversations.data.map((conversation) => (
                        <li key={conversation.id}>
                            <Link
                                href={ConversationController.show(
                                    conversation.id,
                                )}
                                prefetch={false}
                                className="flex items-start gap-3 px-4 py-4 hover:bg-accent/40 sm:px-6"
                            >
                                <UserAvatar
                                    user={conversation.other}
                                    className="size-10 shrink-0"
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="truncate font-medium">
                                            {conversation.other.name}
                                        </span>
                                        {conversation.muted && (
                                            <VolumeX
                                                className="size-4 shrink-0 text-muted-foreground"
                                                aria-label="Muted"
                                            />
                                        )}
                                        {conversation.unread && (
                                            <span className="ml-auto size-2 shrink-0 rounded-full bg-primary">
                                                <span className="sr-only">
                                                    Unread
                                                </span>
                                            </span>
                                        )}
                                    </div>
                                    <p className="truncate text-xs text-muted-foreground">
                                        @{conversation.other.username}
                                    </p>
                                    <p
                                        className={`mt-1 truncate text-sm ${conversation.unread ? 'font-medium' : 'text-muted-foreground'}`}
                                    >
                                        {conversation.last_message?.body}
                                    </p>
                                    {conversation.status !== 'accepted' && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {conversation.incoming_request
                                                ? 'Message request'
                                                : 'Waiting for acceptance'}
                                        </p>
                                    )}
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex justify-between gap-3 p-4">
                {conversations.prev_page_url && (
                    <Button variant="outline" asChild>
                        <Link href={conversations.prev_page_url}>
                            Newer conversations
                        </Link>
                    </Button>
                )}
                {conversations.next_page_url && (
                    <Button variant="outline" asChild>
                        <Link href={conversations.next_page_url}>
                            Older conversations
                        </Link>
                    </Button>
                )}
            </div>
        </>
    );
}
Index.layout = [AppLayout];
