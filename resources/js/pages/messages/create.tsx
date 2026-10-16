import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConversationController from '@/actions/App/Http/Controllers/ConversationController';
import MessagingSettingsController from '@/actions/App/Http/Controllers/MessagingSettingsController';
import { MessageComposer } from '@/components/message-composer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { UserAvatar } from '@/components/user-avatar';
import AppLayout from '@/layouts/app-layout';
import type { User } from '@/types';

export default function Create({
    recipient,
    canSend,
    existing,
    enabled,
}: {
    recipient: User | null;
    canSend: boolean;
    existing: string | null;
    enabled: boolean;
}) {
    const [username, setUsername] = useState(recipient?.username ?? '');
    const [error, setError] = useState('');
    return (
        <>
            <Head title="New message" />
            <header className="flex h-14 items-center gap-4 border-b px-4 sm:px-6">
                <Link
                    href={ConversationController.index()}
                    className="text-sm text-primary"
                >
                    Messages
                </Link>
                <h1 className="font-semibold">New message</h1>
            </header>
            <form
                className="space-y-3 px-4 py-6 sm:px-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    setError('');
                    router.get(
                        ConversationController.create().url,
                        { username: username.trim().replace(/^@/, '') },
                        {
                            onError: (errors) =>
                                setError(
                                    errors.username ??
                                        'Could not find that developer.',
                                ),
                        },
                    );
                }}
            >
                <label htmlFor="recipient" className="text-sm font-medium">
                    GitHub username
                </label>
                <div className="flex gap-2">
                    <Input
                        id="recipient"
                        value={username}
                        onChange={(event) => setUsername(event.target.value)}
                        placeholder="username"
                        autoComplete="off"
                        className="text-base"
                        required
                    />
                    <Button type="submit" variant="outline">
                        Find
                    </Button>
                </div>
                <p role="status" className="min-h-5 text-sm text-destructive">
                    {error}
                </p>
            </form>
            {!enabled ? (
                <p className="px-4 pb-6 text-sm sm:px-6">
                    <Link
                        href={MessagingSettingsController.edit()}
                        className="text-primary underline"
                    >
                        Enable direct messages in settings
                    </Link>{' '}
                    before sending a message.
                </p>
            ) : (
                recipient && (
                    <>
                        <div className="flex items-center gap-3 border-t px-4 py-4 sm:px-6">
                            <UserAvatar user={recipient} className="size-10" />
                            <div className="min-w-0">
                                <p className="truncate font-medium">
                                    {recipient.name}
                                </p>
                                <p className="truncate text-sm text-muted-foreground">
                                    @{recipient.username}
                                </p>
                            </div>
                        </div>
                        {existing ? (
                            <div className="px-4 pb-6 sm:px-6">
                                <Button asChild>
                                    <Link
                                        href={ConversationController.show(
                                            existing,
                                        )}
                                    >
                                        Open conversation
                                    </Link>
                                </Button>
                            </div>
                        ) : canSend ? (
                            <>
                                <p className="px-4 pb-4 text-sm text-muted-foreground sm:px-6">
                                    If this person does not follow you, your
                                    first message will be a request. You can
                                    send more once they accept.
                                </p>
                                <MessageComposer
                                    key={recipient.id}
                                    recipient={recipient}
                                />
                            </>
                        ) : (
                            <p className="px-4 pb-6 text-sm text-muted-foreground sm:px-6">
                                You cannot message this person right now. Their
                                privacy settings may not allow a new
                                conversation.
                            </p>
                        )}
                    </>
                )
            )}
        </>
    );
}
Create.layout = [AppLayout];
