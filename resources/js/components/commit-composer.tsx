import { Form, Link, usePage } from '@inertiajs/react';
import { ChartBar, Clock3 } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import CommitController from '@/actions/App/Http/Controllers/CommitController';
import ScheduledCommitController from '@/actions/App/Http/Controllers/ScheduledCommitController';
import { BodyCounter } from '@/components/body-counter';
import {
    ImagePickerButton,
    ImagePickerPreviews,
    imageErrorFrom,
} from '@/components/commit-image-picker';
import {
    ComposerPreviewBody,
    PreviewToggle,
    useComposerPreview,
} from '@/components/composer-preview';
import { EmojiSuggestionHint } from '@/components/emoji-suggestion';
import { GraphRail, RailAvatar } from '@/components/graph-rail';
import InputError from '@/components/input-error';
import { LinkCardPicker } from '@/components/link-card-picker';
import { MentionTextarea } from '@/components/mention-textarea';
import type { PollDraft } from '@/components/poll-editor';
import { emptyPollDraft, PollEditor } from '@/components/poll-editor';
import { SecretWarning } from '@/components/secret-warning';
import { StashLabel } from '@/components/stash-label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useImageAttachments } from '@/hooks/use-image-attachments';
import { useLinkCard } from '@/hooks/use-link-card';
import type { UseLinkCardReturn } from '@/hooks/use-link-card';
import { useSecretWarning } from '@/hooks/use-secret-warning';
import type { UseStashReturn } from '@/hooks/use-stash';
import {
    discardSeriesStash,
    moveCaretToEnd,
    useStash,
} from '@/hooks/use-stash';
import { useUser } from '@/hooks/use-user';
import { maxBodyLength, minBodyLength } from '@/lib/commit';
import { completeEmoji, suggestEmoji } from '@/lib/emoji';
import { hasModKey, useKeyLabels } from '@/lib/keyboard';
import { characterCount, cn, laneColor } from '@/lib/utils';
import type { User } from '@/types';

function focusBody(): void {
    requestAnimationFrame(() => document.getElementById('body')?.focus());
}

function useCommitSchedule() {
    const [isScheduling, setIsScheduling] = useState(false);
    const [scheduledAt, setScheduledAt] = useState('');
    const [timezone, setTimezone] = useState('');

    return {
        isScheduling,
        setIsScheduling,
        scheduledAt,
        setScheduledAt,
        timezone,
        setTimezone,
    };
}

function CommitComposerBody({
    user,
    stash,
    attachments,
    linkCard,
    schedule,
    poll,
    setPoll,
    secrets,
    isSubmittable,
    processing,
    errors,
    onKeyDown,
    drop,
}: {
    user: User;
    stash: UseStashReturn;
    attachments: ReturnType<typeof useImageAttachments>;
    linkCard: UseLinkCardReturn;
    schedule: ReturnType<typeof useCommitSchedule>;
    poll: PollDraft | null;
    setPoll: (poll: PollDraft | null) => void;
    secrets: ReturnType<typeof useSecretWarning>;
    isSubmittable: boolean;
    processing: boolean;
    errors: Record<string, string>;
    onKeyDown: (event: KeyboardEvent<HTMLTextAreaElement>) => void;
    drop: () => void;
}) {
    const keys = useKeyLabels();
    const { body, isRestored, updateBody } = stash;
    const { auth } = usePage().props;
    const {
        isScheduling,
        scheduledAt,
        setScheduledAt,
        timezone,
        setTimezone,
        setIsScheduling,
    } = schedule;
    const hasImages = attachments.images.length > 0;
    const isSchedulingImages = isScheduling && hasImages;
    const isFilled = characterCount(body.trim()) >= minBodyLength || hasImages;
    const suggestion = suggestEmoji(body);
    const error = errors.body ?? errors.link_preview_url;
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const preview = useComposerPreview({ body, textareaRef, isSubmittable });

    return (
        <div className="flex">
            <GraphRail
                lane={laneColor(user.lane)}
                variant="staged"
                isFilled={isFilled}
                node={<RailAvatar user={user} isStaged={!isFilled} />}
            />

            <div className="flex min-w-0 flex-1 border-b pt-3 pr-4 pb-3">
                <div className="min-w-0 flex-1">
                    <label htmlFor="body" className="sr-only">
                        Body
                    </label>
                    <div className="grid">
                        <MentionTextarea
                            ref={textareaRef}
                            id="body"
                            name="body"
                            rows={1}
                            value={body}
                            onValueChange={updateBody}
                            onPaste={
                                poll === null ? attachments.onPaste : undefined
                            }
                            onDragOver={
                                poll === null
                                    ? attachments.onDragOver
                                    : undefined
                            }
                            onDrop={
                                poll === null ? attachments.onDrop : undefined
                            }
                            onKeyDown={(event) =>
                                preview.handleShortcut(event) ||
                                onKeyDown(event)
                            }
                            onFocus={isRestored ? moveCaretToEnd : undefined}
                            maxLength={maxBodyLength}
                            placeholder={
                                poll ? 'Ask a question' : 'What did you ship?'
                            }
                            autoComplete="off"
                            aria-invalid={error ? true : undefined}
                            aria-describedby="body-count"
                            className={cn(
                                'col-start-1 row-start-1 block field-sizing-content max-h-[60dvh] min-h-10 w-full resize-none overflow-x-hidden overflow-y-auto bg-transparent py-1.5 text-[17px] leading-relaxed outline-none placeholder:text-muted-foreground focus-visible:outline-none',
                                { invisible: preview.isPreviewing },
                            )}
                        />
                        {preview.isPreviewing && (
                            <ComposerPreviewBody
                                preview={preview}
                                url={
                                    isScheduling
                                        ? (linkCard.links[0] ?? null)
                                        : linkCard.link
                                }
                                images={attachments.images}
                                descriptions={attachments.descriptions}
                                className="col-start-1 row-start-1 py-1.5"
                            />
                        )}
                    </div>

                    {!preview.isPreviewing && (
                        <EmojiSuggestionHint
                            suggestion={suggestion}
                            className="mt-1.5"
                        />
                    )}

                    <SecretWarning
                        body={body}
                        findings={secrets.findings}
                        textarea={textareaRef}
                        onChange={updateBody}
                        confirming={secrets.confirming}
                        onConfirmingChange={secrets.setConfirming}
                        onApprove={secrets.approve}
                        onEdit={preview.close}
                    />
                    <InputError message={error} />

                    {!preview.isPreviewing && (
                        <ImagePickerPreviews
                            images={attachments.images}
                            descriptions={attachments.descriptions}
                            onDescribe={attachments.describe}
                            onRemove={attachments.remove}
                        />
                    )}
                    <InputError
                        message={
                            attachments.error ??
                            (isSchedulingImages
                                ? "Images can't be scheduled yet. Remove them to schedule this commit."
                                : imageErrorFrom(errors))
                        }
                        className="mt-1"
                    />

                    {poll && (
                        <PollEditor
                            poll={poll}
                            errors={errors}
                            onChange={setPoll}
                            onRemove={() => {
                                setPoll(null);
                                focusBody();
                            }}
                        />
                    )}

                    {isScheduling && (
                        <div
                            id="commit-schedule"
                            className="mt-3 space-y-2 rounded-lg border bg-muted/30 p-3"
                        >
                            <label
                                htmlFor="scheduled-at"
                                className="text-sm font-medium"
                            >
                                Submit at
                            </label>
                            <Input
                                id="scheduled-at"
                                type="datetime-local"
                                required
                                value={scheduledAt}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    setScheduledAt(value);
                                    const date = new Date(value);
                                    const pad = (n: number) =>
                                        String(n).padStart(2, '0');
                                    const local = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
                                    event.target.setCustomValidity(
                                        value && local !== value
                                            ? 'This time does not exist in your timezone. Choose another time.'
                                            : '',
                                    );
                                }}
                                aria-describedby="schedule-help schedule-error"
                                aria-invalid={
                                    errors.scheduled_at ? true : undefined
                                }
                            />
                            <p
                                id="schedule-help"
                                className="text-xs leading-relaxed text-muted-foreground"
                            >
                                {timezone}
                                {scheduledAt &&
                                !Number.isNaN(new Date(scheduledAt).getTime())
                                    ? ` (${new Intl.DateTimeFormat(undefined, { timeZoneName: 'shortOffset' }).formatToParts(new Date(scheduledAt)).find((part) => part.type === 'timeZoneName')?.value})`
                                    : ''}
                                . Within 30 days. Private until submitted;{' '}
                                {auth.isAdmin
                                    ? 'your commit will be approved automatically.'
                                    : 'then submitted for review, not published automatically.'}
                            </p>
                            <InputError
                                id="schedule-error"
                                message={errors.scheduled_at}
                            />
                            <Link
                                href={ScheduledCommitController.index()}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                Scheduled commits →
                            </Link>
                        </div>
                    )}

                    <div className="mt-2 flex flex-wrap items-center justify-end gap-x-4 gap-y-2">
                        <div className="mr-auto flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
                            {!isScheduling && (
                                <ImagePickerButton
                                    count={attachments.images.length}
                                    disabled={processing || poll !== null}
                                    onPick={attachments.attach}
                                    className="-ml-2"
                                />
                            )}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={
                                    processing ||
                                    poll !== null ||
                                    hasImages ||
                                    isScheduling
                                }
                                onClick={() => {
                                    setPoll(emptyPollDraft);
                                    requestAnimationFrame(() =>
                                        document
                                            .getElementById('poll-option-0')
                                            ?.focus(),
                                    );
                                }}
                                aria-label="Add poll"
                                title={
                                    isScheduling
                                        ? 'Polls cannot be scheduled yet'
                                        : hasImages
                                          ? 'A commit can have images or a poll, not both'
                                          : 'Add poll'
                                }
                                className="text-muted-foreground"
                                data-test="add-poll-button"
                            >
                                <ChartBar className="size-5" />
                            </Button>
                            {!isScheduling && (
                                <LinkCardPicker
                                    linkCard={linkCard}
                                    disabled={processing}
                                    className="-ml-3"
                                />
                            )}
                            <PreviewToggle
                                preview={preview}
                                className="-ml-3"
                            />
                            {isRestored && (
                                <StashLabel
                                    onDrop={() => {
                                        drop();
                                        focusBody();
                                    }}
                                    className="text-[13px]"
                                />
                            )}
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-expanded={isScheduling}
                            aria-controls="commit-schedule"
                            disabled={
                                processing ||
                                (!isScheduling && (hasImages || poll !== null))
                            }
                            title={
                                !isScheduling && poll !== null
                                    ? 'Polls cannot be scheduled yet'
                                    : !isScheduling && hasImages
                                      ? "Images can't be scheduled yet"
                                      : undefined
                            }
                            onClick={() => {
                                setTimezone(
                                    Intl.DateTimeFormat().resolvedOptions()
                                        .timeZone,
                                );
                                setIsScheduling(!isScheduling);
                            }}
                            className="gap-1.5 px-2 text-muted-foreground"
                        >
                            <Clock3 className="size-3.5" aria-hidden="true" />
                            Schedule
                        </Button>
                        <BodyCounter
                            id="body-count"
                            body={body}
                            className="text-[13px]"
                        />
                        <Button
                            type="submit"
                            disabled={processing || !isSubmittable}
                            title={
                                isScheduling
                                    ? `Schedule commit (${keys.combo(keys.mod, keys.enter)})`
                                    : `Commit (${keys.combo(keys.mod, keys.enter)})`
                            }
                            className="min-w-[7.5rem]"
                            data-test="create-commit-button"
                        >
                            {isScheduling
                                ? processing
                                    ? 'Scheduling…'
                                    : 'Schedule commit'
                                : processing
                                  ? 'Committing…'
                                  : 'Commit'}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export function CommitComposer() {
    const schedule = useCommitSchedule();
    const { isScheduling, scheduledAt, setScheduledAt, setIsScheduling } =
        schedule;
    const { user } = useUser();
    const stash = useStash(user.id, 'commit');
    const { body, updateBody } = stash;
    const linkCard = useLinkCard(body);
    const [draftVersion, setDraftVersion] = useState(0);
    const drop = () => {
        stash.drop();
        linkCard.reset();
        setDraftVersion((version) => version + 1);
    };
    const attachments = useImageAttachments(
        stash.images,
        stash.descriptions,
        stash.updateImages,
    );
    const [poll, setPoll] = useState<PollDraft | null>(null);
    const secrets = useSecretWarning(body);

    const hasImages = attachments.images.length > 0;
    const isCommittable =
        (characterCount(body.trim()) >= minBodyLength || hasImages) &&
        (poll === null ||
            poll.options.every((option) => option.trim().length > 0));
    const isSubmittable =
        isCommittable &&
        !(isScheduling && hasImages) &&
        (!isScheduling || poll === null);

    useEffect(() => discardSeriesStash(user.id), [user.id]);

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Tab' && suggestEmoji(body) !== null) {
            const completed = completeEmoji(body);

            if (completed !== null) {
                event.preventDefault();
                updateBody(completed);
            }

            return;
        }

        if (event.key === 'Enter' && hasModKey(event) && isSubmittable) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    };

    return (
        <Form
            {...(isScheduling
                ? ScheduledCommitController.store.form()
                : CommitController.store.form())}
            onBefore={secrets.beforeSubmit}
            transform={(data) =>
                isScheduling
                    ? {
                          ...data,
                          scheduled_at: new Date(scheduledAt)
                              .toISOString()
                              .replace(/\.\d{3}Z$/, '+00:00'),
                      }
                    : hasImages
                      ? {
                            ...data,
                            images: attachments.images,
                            image_descriptions: attachments.descriptions,
                        }
                      : data
            }
            options={{ preserveScroll: true }}
            onSuccess={(page) => {
                if (!page.flash.committed && !page.flash.scheduled) {
                    return;
                }

                drop();
                setScheduledAt('');
                setIsScheduling(false);
                attachments.clear();
                setPoll(null);
                focusBody();
            }}
            data-test="commit-composer"
        >
            {({ processing, errors }) => (
                <CommitComposerBody
                    key={draftVersion}
                    user={user}
                    stash={stash}
                    attachments={attachments}
                    linkCard={linkCard}
                    schedule={schedule}
                    poll={poll}
                    setPoll={setPoll}
                    secrets={secrets}
                    isSubmittable={isSubmittable}
                    processing={processing}
                    errors={errors}
                    onKeyDown={onKeyDown}
                    drop={drop}
                />
            )}
        </Form>
    );
}
