import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import ScheduledCommitController from '@/actions/App/Http/Controllers/ScheduledCommitController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { localDateTime, scheduledTimestamp } from '@/lib/scheduled-commit';

export function RescheduleCommitDialog({
    id,
    scheduledAt,
    disabled,
}: {
    id: string;
    scheduledAt: string;
    disabled: boolean;
}) {
    const [open, setOpen] = useState(false);
    const inputId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const original = localDateTime(new Date(scheduledAt));
    const form = useForm({ scheduled_at: original });
    const date = new Date(form.data.scheduled_at);
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const offset = Number.isNaN(date.getTime())
        ? null
        : new Intl.DateTimeFormat(undefined, { timeZoneName: 'shortOffset' })
              .formatToParts(date)
              .find((part) => part.type === 'timeZoneName')?.value;

    useEffect(() => {
        if (form.errors.scheduled_at && !form.processing) {
            inputRef.current?.focus();
        }
    }, [form.errors.scheduled_at, form.processing]);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const timestamp = scheduledTimestamp(form.data.scheduled_at);

        if (timestamp === null) {
            inputRef.current?.reportValidity();

            return;
        }

        form.transform(() => ({ scheduled_at: timestamp }));
        form.put(ScheduledCommitController.update.url(id), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    form.setData('scheduled_at', original);
                    form.clearErrors();
                }

                setOpen(next);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    ref={triggerRef}
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    aria-label={`Reschedule the commit scheduled for ${new Date(scheduledAt).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}`}
                    data-test="reschedule-commit-button"
                >
                    Reschedule
                </Button>
            </DialogTrigger>
            <DialogContent
                onCloseAutoFocus={(event) => {
                    if (!triggerRef.current?.isConnected) {
                        event.preventDefault();
                        document
                            .getElementById('scheduled-commits-heading')
                            ?.focus();
                    }
                }}
            >
                <DialogTitle>Reschedule commit</DialogTitle>
                <DialogDescription>
                    Choose a new submission time within the next 30 days.
                </DialogDescription>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <label
                            htmlFor={inputId}
                            className="text-sm font-medium"
                        >
                            Submit at
                        </label>
                        <Input
                            ref={inputRef}
                            id={inputId}
                            type="datetime-local"
                            required
                            disabled={form.processing}
                            value={form.data.scheduled_at}
                            onChange={(event) => {
                                const value = event.currentTarget.value;
                                form.setData('scheduled_at', value);
                                event.currentTarget.setCustomValidity(
                                    value && scheduledTimestamp(value) === null
                                        ? 'This time does not exist in your timezone. Choose another time.'
                                        : '',
                                );
                            }}
                            aria-invalid={
                                form.errors.scheduled_at ? true : undefined
                            }
                            aria-describedby={`${inputId}-help${form.errors.scheduled_at ? ` ${inputId}-error` : ''}`}
                        />
                        <p
                            id={`${inputId}-help`}
                            className="text-xs text-muted-foreground"
                        >
                            Times in {timezone}
                            {offset ? ` (${offset})` : ''}.
                        </p>
                        <InputError
                            id={`${inputId}-error`}
                            message={form.errors.scheduled_at}
                        />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                            >
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.scheduled_at === original
                            }
                            data-test="save-reschedule-button"
                        >
                            {form.processing ? 'Saving…' : 'Save time'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
