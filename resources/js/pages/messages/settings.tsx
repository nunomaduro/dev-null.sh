import { Head, useForm } from '@inertiajs/react';
import MessagingSettingsController from '@/actions/App/Http/Controllers/MessagingSettingsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

type Policy = 'off' | 'following' | 'everyone';

export default function Settings({
    policy,
    readReceipts,
}: {
    policy: Policy;
    readReceipts: boolean;
}) {
    const form = useForm({ dm_policy: policy, dm_read_receipts: readReceipts });
    return (
        <>
            <Head title="Messaging settings" />
            <form
                className="space-y-6 px-4 py-6 sm:px-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(MessagingSettingsController.update().url, {
                        preserveScroll: true,
                    });
                }}
            >
                <Heading
                    title="Direct messages"
                    description="Choose who can start a private conversation with you."
                />
                <fieldset className="space-y-3">
                    <legend className="mb-3 font-medium">
                        Who can message you?
                    </legend>
                    {(
                        [
                            [
                                'off',
                                'Off',
                                'Pause sending and receiving. Your conversation history stays available.',
                            ],
                            [
                                'following',
                                'People I follow',
                                'People you follow can start conversations. People you have already accepted can still reply.',
                            ],
                            [
                                'everyone',
                                'Everyone',
                                'People you follow go straight to your inbox. Everyone else can send one message request.',
                            ],
                        ] as const
                    ).map(([value, title, description]) => (
                        <label
                            key={value}
                            className="flex cursor-pointer gap-3 rounded-lg border p-4 has-[:checked]:border-primary has-[:checked]:bg-primary-soft"
                        >
                            <input
                                type="radio"
                                name="dm_policy"
                                value={value}
                                checked={form.data.dm_policy === value}
                                onChange={() =>
                                    form.setData('dm_policy', value)
                                }
                                className="mt-1 size-4 shrink-0 accent-primary"
                            />
                            <span>
                                <span className="block font-medium">
                                    {title}
                                </span>
                                <span className="mt-1 block text-sm text-muted-foreground">
                                    {description}
                                </span>
                            </span>
                        </label>
                    ))}
                </fieldset>
                <label className="flex items-start gap-3 rounded-lg border p-4">
                    <input
                        type="checkbox"
                        checked={form.data.dm_read_receipts}
                        onChange={(event) =>
                            form.setData(
                                'dm_read_receipts',
                                event.target.checked,
                            )
                        }
                        className="mt-1 size-4 accent-primary"
                    />
                    <span>
                        <span className="block font-medium">
                            Share read receipts
                        </span>
                        <span className="mt-1 block text-sm text-muted-foreground">
                            Show when you have read a message. You will see
                            receipts only when both people enable this. Requests
                            never send receipts before acceptance.
                        </span>
                    </span>
                </label>
                <p className="text-sm text-muted-foreground">
                    Blocking always stops messages in both directions. Messages
                    are encrypted in storage, but are not end-to-end encrypted.
                    Do not send passwords or access tokens.
                </p>
                <div className="flex items-center gap-3">
                    <Button
                        disabled={form.processing}
                        type="submit"
                        className="min-w-32"
                    >
                        {form.processing ? 'Saving…' : 'Save settings'}
                    </Button>
                    <span
                        role="status"
                        className="text-sm text-muted-foreground"
                    >
                        {form.recentlySuccessful
                            ? 'Saved.'
                            : Object.values(form.errors).join(' ')}
                    </span>
                </div>
            </form>
        </>
    );
}
Settings.layout = [AppLayout, SettingsLayout];
