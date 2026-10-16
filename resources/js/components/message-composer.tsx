import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import DirectMessageController from '@/actions/App/Http/Controllers/DirectMessageController';
import { Button } from '@/components/ui/button';
import type { User } from '@/types';

export function MessageComposer({ recipient }: { recipient: User }) {
    const form = useForm({ body: '', client_id: crypto.randomUUID() });
    const [networkError, setNetworkError] = useState(false);

    const send = () => {
        if (form.processing || !form.data.body.trim()) return;
        setNetworkError(false);
        form.post(DirectMessageController.store(recipient).url, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (page.flash.directMessageSent !== form.data.client_id)
                    return;
                form.setData({ body: '', client_id: crypto.randomUUID() });
                form.clearErrors();
            },
            onError: () => setNetworkError(true),
        });
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                send();
            }}
            className="border-t bg-card p-4 sm:px-6"
        >
            <label htmlFor="message-body" className="sr-only">
                Message to @{recipient.username}
            </label>
            <textarea
                id="message-body"
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                onKeyDown={(event) => {
                    if (
                        event.key === 'Enter' &&
                        !event.nativeEvent.isComposing &&
                        !event.shiftKey
                    ) {
                        event.preventDefault();
                        send();
                    }
                }}
                placeholder="Write a message…"
                maxLength={4000}
                disabled={form.processing}
                className="min-h-24 w-full resize-y overflow-x-hidden rounded-md border border-input bg-transparent px-3 py-2 text-base focus-visible:outline-2 focus-visible:outline-ring"
                aria-describedby="message-feedback"
                autoComplete="off"
            />
            <div className="mt-3 flex items-center justify-between gap-3">
                <span className="text-xs text-muted-foreground">
                    Enter to send · Shift+Enter for a new line
                </span>
                <Button
                    type="submit"
                    disabled={form.processing || !form.data.body.trim()}
                    className="min-w-24"
                >
                    <Send className="size-4" />
                    {form.processing ? 'Sending…' : 'Send'}
                </Button>
            </div>
            <p
                id="message-feedback"
                role="status"
                className="mt-2 min-h-5 text-sm text-destructive"
            >
                {form.errors.body ??
                    form.errors.client_id ??
                    (networkError
                        ? 'Message not sent. Your text is still here; try again.'
                        : '')}
            </p>
        </form>
    );
}
